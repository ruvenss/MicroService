<?php

declare(strict_types=1);

use App\Core\Plugin\ResourceEvent;
use App\Libraries\WebhookDispatcher;
use App\Models\WebhookOutboxModel;
use CodeIgniter\Events\Events;
use Config\Webhooks;
use Tests\Support\FeatureTestCase;

/**
 * Outbound webhooks: resource mutations enqueue signed outbox rows that
 * `webhooks:dispatch` delivers to n8n. Delivery is exercised via an injected
 * sender (no real HTTP).
 *
 * @internal
 */
final class WebhookTest extends FeatureTestCase
{
    /** @var array<string, string> */
    private array $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auth = $this->authHeaders(['products:*']);

        // Webhooks are a transactional outbox enqueued directly by the controller
        // inside the mutation's transaction (not via post-commit events), so there
        // are no event listeners to register here. A subscription turns enqueue on.
        $this->subscribe([['url' => 'https://n8n.example/webhook/abc', 'secret' => 's3cr3t', 'events' => ['*']]]);
    }

    protected function tearDown(): void
    {
        WebhookDispatcher::$sender         = null;
        WebhookDispatcher::$configOverride = null;
        parent::tearDown();
    }

    /**
     * @param list<array{url: string, secret: string, events: list<string>}> $subscriptions
     */
    private function subscribe(array $subscriptions): void
    {
        $config                            = new Webhooks();
        $config->subscriptions             = $subscriptions;
        WebhookDispatcher::$configOverride = $config;
    }

    private function createProduct(string $sku): int
    {
        return json_decode((string) $this->withHeaders($this->auth)->withBodyFormat('json')
            ->post('api/v1/products', ['sku' => $sku, 'name' => 'N', 'price' => '1.00'])
            ->response()->getBody(), true)['data']['id'];
    }

    public function testMutationEnqueuesASignedWebhook(): void
    {
        $id = $this->createProduct('WH-1');

        $rows = (new WebhookOutboxModel())->where('event', 'products.afterCreate')->findAll();
        $this->assertCount(1, $rows);

        $row = $rows[0];
        $this->assertSame('https://n8n.example/webhook/abc', $row['target_url']);
        $this->assertSame('pending', $row['status']);
        // Stored as the bare hex digest over the exact payload bytes (the `sha256=`
        // tag is added on the wire, asserted in the delivery test).
        $this->assertSame(hash_hmac('sha256', (string) $row['payload_json'], 's3cr3t'), $row['signature']);

        $payload = json_decode((string) $row['payload_json'], true);
        $this->assertSame('products.afterCreate', $payload['event']);
        $this->assertSame($id, $payload['id']);
        // The envelope `timestamp` is the same Z-suffixed UTC shape as the `data`
        // record timestamps it wraps — one parse rule for the whole payload in n8n.
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $payload['timestamp']);
    }

    public function testWebhookPayloadEmitsRawUtf8LikeTheApiResponses(): void
    {
        // The webhook `data` n8n receives must be raw UTF-8 / unescaped slashes, the
        // same wire format as a live GET — not \u-escaped. Assert on the stored
        // payload_json bytes (what gets POSTed and signed).
        json_decode((string) $this->withHeaders($this->auth)->withBodyFormat('json')
            ->post('api/v1/products', ['sku' => 'WH-UTF/1', 'name' => 'Café ☕', 'price' => '1.00'])
            ->response()->getBody(), true);

        $payload   = (string) (new WebhookOutboxModel())->where('event', 'products.afterCreate')->first()['payload_json'];
        $backslash = chr(92);

        $this->assertStringContainsString('Café ☕', $payload);          // raw UTF-8
        $this->assertStringContainsString('WH-UTF/1', $payload);          // unescaped slash
        $this->assertStringNotContainsString($backslash . 'u', $payload); // no \uXXXX
        $this->assertStringNotContainsString($backslash . '/', $payload); // no \/
    }

    public function testDeleteDataAndUpdatePreviousAreTypedLikeTheLiveApi(): void
    {
        // Every webhook payload an n8n workflow receives must be typed like a live GET:
        // delete `data` and update `previous` used to be raw MySQLi strings while
        // create/update `data` was cast. Use a fractional price so the float survives
        // the JSON round-trip.
        $id = json_decode((string) $this->withHeaders($this->auth)->withBodyFormat('json')
            ->post('api/v1/products', ['sku' => 'WH-TYPED', 'name' => 'N', 'price' => '3.50'])
            ->response()->getBody(), true)['data']['id'];
        $this->withHeaders($this->auth)->withBodyFormat('json')->patch("api/v1/products/{$id}", ['price' => '4.75']);
        $this->withHeaders($this->auth)->delete("api/v1/products/{$id}");

        $model = new WebhookOutboxModel();

        // afterUpdate: data (new) and previous (old) both typed.
        $update = json_decode((string) $model->where('event', 'products.afterUpdate')->first()['payload_json'], true);
        $this->assertSame(4.75, $update['data']['price']);
        $this->assertSame(3.50, $update['previous']['price']);          // previous now cast, not "3.50"
        $this->assertIsInt($update['previous']['id']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $update['previous']['created_at']);

        // afterDelete: data typed (was raw hide()).
        $delete = json_decode((string) $model->where('event', 'products.afterDelete')->first()['payload_json'], true);
        $this->assertSame(4.75, $delete['data']['price']);
        $this->assertIsInt($delete['data']['id']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $delete['data']['created_at']);
    }

    public function testEnqueueIsControllerDrivenNotPostCommitEvent(): void
    {
        // The outbox is written by the controller inside the mutation transaction,
        // not by a post-commit resource.after* listener. Prove it: drop every such
        // listener and a mutation must still enqueue exactly one row.
        foreach (['afterCreate', 'afterUpdate', 'afterDelete', 'afterRestore'] as $e) {
            Events::removeAllListeners('resource.' . $e);
        }

        $this->createProduct('WH-CTRL');

        $this->assertSame(1, (new WebhookOutboxModel())->where('event', 'products.afterCreate')->countAllResults());
    }

    public function testDispatchDeliversAndSignsTheRequest(): void
    {
        $this->createProduct('WH-2');

        $captured                 = [];
        WebhookDispatcher::$sender = static function (string $url, array $headers, string $body) use (&$captured): int {
            $captured = ['url' => $url, 'headers' => $headers, 'body' => $body];

            return 200;
        };

        $result = WebhookDispatcher::dispatch();

        $this->assertSame(1, $result['sent']);
        $this->assertSame('https://n8n.example/webhook/abc', $captured['url']);
        $this->assertSame('sha256=' . hash_hmac('sha256', $captured['body'], 's3cr3t'), $captured['headers']['X-Signature']);
        $this->assertSame('delivered', (new WebhookOutboxModel())->first()['status']);

        // Delivery carries a stable dedupe id, an attempt counter, and a neutral UA.
        $row = (new WebhookOutboxModel())->first();
        $this->assertSame((string) $row['id'], $captured['headers']['X-Webhook-Id']);
        $this->assertSame('1', $captured['headers']['X-Webhook-Attempt']);
        $this->assertSame('MicroService-Webhook/1.0', $captured['headers']['User-Agent']);
    }

    public function testEnqueueFailureIsLoggedNotSilentlyLost(): void
    {
        // Enqueue is best-effort (it must never break the API response), but a swallowed
        // failure means a webhook — an n8n trigger — was silently lost. Force a failure
        // with a target_url past the column limit and assert it is logged, so a dropped
        // trigger is observable rather than vanishing (the exact silent-loss mode that
        // once masked a schema bug).
        \Config\Services::injectMock('logger', new \CodeIgniter\Test\TestLogger(new \Config\Logger()));
        // target_url is VARCHAR(500); a 600-char URL makes the outbox INSERT throw.
        $this->subscribe([['url' => 'https://n8n.example/' . str_repeat('x', 600), 'secret' => 's', 'events' => ['*']]]);

        // Drive enqueue directly (the HTTP pipeline would re-instantiate the logger and
        // drop the injected mock); this is the same call the controller makes in-txn.
        WebhookDispatcher::enqueue(new ResourceEvent('products', 'afterCreate', row: ['id' => 1, 'sku' => 'X', 'name' => 'N', 'price' => 1.0]));

        $this->assertSame(0, (new WebhookOutboxModel())->countAllResults()); // nothing enqueued
        $this->assertLogContains('critical', 'Webhook enqueue failed for products.afterCreate');
    }

    public function testEnqueueFailureRollsBackTheWriteAndReturns500(): void
    {
        // The outbox insert shares the mutation's transaction (atomic — no dual-write
        // gap). If it fails, the managed transaction rolls the whole change back, so the
        // API must report 500, never a 201 for a write that didn't persist. (Before the
        // fix the controller ignored transComplete() and returned 201 + an id for a
        // record that was actually rolled back.)
        $auth = $this->authHeaders(['products:*']);
        $this->subscribe([['url' => 'https://n8n.example/' . str_repeat('x', 600), 'secret' => 's', 'events' => ['*']]]);

        $result = $this->withHeaders($auth)->withBodyFormat('json')
            ->post('api/v1/products', ['sku' => 'TXN-ROLLBACK', 'name' => 'N', 'price' => '1.00']);

        $result->assertStatus(500);
        $this->assertStringContainsString('application/problem+json', $result->response()->getHeaderLine('Content-Type'));

        // The write did not persist: the sku is absent.
        $list = json_decode((string) $this->withHeaders($auth)->get('api/v1/products?filter[sku]=TXN-ROLLBACK')->response()->getBody(), true);
        $this->assertCount(0, $list['data']);
    }

    public function testUnsignedSubscriptionOmitsTheSignatureHeader(): void
    {
        // A subscription with no secret is unsigned: the stored signature is empty and
        // delivery must OMIT X-Signature entirely (an empty header would invite a
        // receiver to "verify" against nothing).
        $this->subscribe([['url' => 'https://n8n.example/webhook/abc', 'secret' => '', 'events' => ['*']]]);
        $this->createProduct('WH-UNSIGNED');

        $this->assertSame('', (new WebhookOutboxModel())->first()['signature']);

        $captured                 = [];
        WebhookDispatcher::$sender = static function (string $url, array $headers) use (&$captured): int {
            $captured = $headers;

            return 200;
        };
        WebhookDispatcher::dispatch();

        $this->assertArrayNotHasKey('X-Signature', $captured);
        $this->assertSame('products.afterCreate', $captured['X-Event']); // other headers still present
    }

    public function testWebhookIdIsStableAcrossRetriesButAttemptGrows(): void
    {
        $this->createProduct('WH-ID');
        $model = new WebhookOutboxModel();
        $id    = $model->first()['id'];

        $seen = [];
        WebhookDispatcher::$sender = static function (string $url, array $headers) use (&$seen): int {
            $seen[] = ['id' => $headers['X-Webhook-Id'], 'attempt' => $headers['X-Webhook-Attempt']];

            return 500; // fail so the row is retried
        };

        WebhookDispatcher::dispatch();
        $model->update($id, ['next_attempt_at' => date('Y-m-d H:i:s', time() - 1)]); // clear backoff
        WebhookDispatcher::dispatch();

        $this->assertSame((string) $id, $seen[0]['id']);
        $this->assertSame((string) $id, $seen[1]['id']);   // same dedupe id across retries
        $this->assertSame('1', $seen[0]['attempt']);
        $this->assertSame('2', $seen[1]['attempt']);       // attempt counter grows
    }

    public function testFailedDeliveryBacksOffThenRetries(): void
    {
        $this->createProduct('WH-3');
        $model = new WebhookOutboxModel();

        WebhookDispatcher::$sender = static fn (): int => 500;
        $this->assertSame(1, WebhookDispatcher::dispatch()['failed']);

        $row = $model->first();
        $this->assertSame('failed', $row['status']);
        $this->assertSame(1, (int) $row['attempts']);
        $this->assertNotNull($row['next_attempt_at']);                       // backoff scheduled
        $this->assertGreaterThan(time(), strtotime((string) $row['next_attempt_at']));

        // An immediate run must NOT re-deliver — the backoff has not elapsed.
        WebhookDispatcher::$sender = static fn (): int => 200;
        $this->assertSame(0, WebhookDispatcher::dispatch()['processed']);
        $this->assertSame('failed', $model->first()['status']);

        // Once the backoff window passes, the next run delivers it.
        $model->update($row['id'], ['next_attempt_at' => date('Y-m-d H:i:s', time() - 1)]);
        $this->assertSame(1, WebhookDispatcher::dispatch()['sent']);
        $this->assertSame('delivered', $model->first()['status']);
    }

    public function testBackoffGrowsExponentiallyWithAttempts(): void
    {
        $this->createProduct('WH-BO');
        $model = new WebhookOutboxModel();
        $id    = $model->first()['id'];

        WebhookDispatcher::$sender = static fn (): int => 503;

        $delays = [];
        for ($n = 1; $n <= 3; $n++) {
            $before = time();
            WebhookDispatcher::dispatch();
            $row      = $model->find($id);
            $delays[] = strtotime((string) $row['next_attempt_at']) - $before;
            // Fast-forward past the just-scheduled window so the next run re-claims it.
            $model->update($id, ['next_attempt_at' => date('Y-m-d H:i:s', time() - 1)]);
        }

        // Roughly 60, 120, 240s — each at least ~1.5x the previous.
        $this->assertGreaterThan($delays[0], $delays[1]);
        $this->assertGreaterThan($delays[1], $delays[2]);
    }

    public function testConcurrentClaimsPartitionRowsWithoutOverlap(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $this->createProduct("WH-C{$i}");
        }
        $model = new WebhookOutboxModel();

        // Two dispatchers claim concurrently — each must own a disjoint set, so no
        // row is ever POSTed to n8n twice.
        $idsA = array_map('strval', array_column($model->claim('tokenA', 5, 2, 300), 'id'));
        $idsB = array_map('strval', array_column($model->claim('tokenB', 5, 2, 300), 'id'));

        $this->assertCount(2, $idsA);
        $this->assertCount(2, $idsB);
        $this->assertSame([], array_intersect($idsA, $idsB));
    }

    public function testStaleDispatchingRowIsReclaimed(): void
    {
        $this->createProduct('WH-STALE');
        $model = new WebhookOutboxModel();
        $id    = $model->first()['id'];

        // A crashed dispatcher left the row claimed an hour ago and never finished.
        $model->update($id, ['status' => 'dispatching', 'claim_token' => 'dead', 'claimed_at' => date('Y-m-d H:i:s', time() - 3600)]);

        $claimed = $model->claim('fresh', 5, 10, 300);
        $this->assertSame([(string) $id], array_map('strval', array_column($claimed, 'id')));
    }

    public function testFreshlyClaimedRowIsNotStolen(): void
    {
        $this->createProduct('WH-ACTIVE');
        $model = new WebhookOutboxModel();
        $id    = $model->first()['id'];

        // An in-flight claim (just now) must not be reclaimed by another run.
        $model->update($id, ['status' => 'dispatching', 'claim_token' => 'live', 'claimed_at' => date('Y-m-d H:i:s')]);

        $this->assertSame([], $model->claim('other', 5, 10, 300));
    }

    public function testEventFilterOnlyEnqueuesMatchingEvents(): void
    {
        $this->subscribe([['url' => 'https://n8n.example/webhook/del', 'secret' => '', 'events' => ['products.afterDelete']]]);

        $id = $this->createProduct('WH-4');
        $this->assertSame(0, (new WebhookOutboxModel())->countAllResults());

        $this->withHeaders($this->auth)->delete("api/v1/products/{$id}")->assertStatus(204);
        $this->assertSame(1, (new WebhookOutboxModel())->where('event', 'products.afterDelete')->countAllResults());
    }

    public function testNoSubscriptionIsANoOp(): void
    {
        $this->subscribe([]);
        $this->createProduct('WH-5');

        $this->assertSame(0, (new WebhookOutboxModel())->countAllResults());
    }

    public function testWebhookUsesResourcePrimaryKeyNotHardcodedId(): void
    {
        // A resource keyed on 'code', not 'id' — the engine is generic over primaryKey,
        // so the notification must carry the code, not a null 'id'.
        config('Resources')->resources['widgets'] = [
            'table' => 'widgets', 'primaryKey' => 'code', 'fillable' => ['code', 'name'],
            'rules' => ['create' => [], 'update' => []],
            'sortable' => [], 'filterable' => [], 'perPage' => ['default' => 25, 'max' => 100],
            'timestamps' => false,
        ];

        try {
            WebhookDispatcher::enqueue(new \App\Core\Plugin\ResourceEvent('widgets', 'afterCreate', row: ['code' => 'W-1', 'name' => 'Gadget']));

            $row = (new WebhookOutboxModel())->where('resource', 'widgets')->first();
            $this->assertNotNull($row);
            $this->assertSame('W-1', $row['record_id']);                                   // not null
            $this->assertSame('W-1', json_decode((string) $row['payload_json'], true)['id']); // payload id = the code
        } finally {
            unset(config('Resources')->resources['widgets']);
        }
    }

    public function testDeadLetteredRowIsSkippedUntilReplayed(): void
    {
        $this->createProduct('WH-DL');
        $model = new WebhookOutboxModel();
        $id    = $model->first()['id'];

        // Simulate an exhausted retry budget (maxAttempts = 5).
        $model->update($id, ['status' => 'failed', 'attempts' => 5, 'next_attempt_at' => null]);
        $this->assertSame(1, $model->deadLetterCount(5));

        // dispatch() must not touch it — the budget is spent.
        WebhookDispatcher::$sender = static fn (): int => 200;
        $this->assertSame(0, WebhookDispatcher::dispatch()['processed']);

        // Replaying the dead-letter queue resurrects it with a fresh budget...
        $result = WebhookDispatcher::retryDeadLettered();
        $this->assertSame(1, $result['deadLettered']);
        $this->assertSame(1, $result['resurrected']);
        $this->assertSame('pending', $model->find($id)['status']);
        $this->assertSame(0, (int) $model->find($id)['attempts']);

        // ...and the next dispatch delivers it.
        $this->assertSame(1, WebhookDispatcher::dispatch()['sent']);
    }

    public function testRetryCommandRequeuesDeadLetters(): void
    {
        $this->createProduct('WH-DLC');
        $model = new WebhookOutboxModel();
        $id    = $model->first()['id'];
        $model->update($id, ['status' => 'failed', 'attempts' => 5]);

        ob_start();
        command('webhooks:retry');
        ob_end_clean();

        $this->assertSame('pending', $model->find($id)['status']);
    }

    public function testRetryDryRunChangesNothing(): void
    {
        $this->createProduct('WH-DLDRY');
        $model = new WebhookOutboxModel();
        $id    = $model->first()['id'];
        $model->update($id, ['status' => 'failed', 'attempts' => 5]);

        ob_start();
        command('webhooks:retry --dry-run');
        ob_end_clean();

        $row = $model->find($id);
        $this->assertSame('failed', $row['status']);
        $this->assertSame(5, (int) $row['attempts']);
    }
}

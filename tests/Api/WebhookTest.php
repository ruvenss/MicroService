<?php

declare(strict_types=1);

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
        $this->assertSame(hash_hmac('sha256', (string) $row['payload_json'], 's3cr3t'), $row['signature']);

        $payload = json_decode((string) $row['payload_json'], true);
        $this->assertSame('products.afterCreate', $payload['event']);
        $this->assertSame($id, $payload['id']);
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
        $this->assertSame(hash_hmac('sha256', $captured['body'], 's3cr3t'), $captured['headers']['X-Signature']);
        $this->assertSame('delivered', (new WebhookOutboxModel())->first()['status']);
    }

    public function testFailedDeliveryIsMarkedFailedThenRetriable(): void
    {
        $this->createProduct('WH-3');

        WebhookDispatcher::$sender = static fn (): int => 500;
        $this->assertSame(1, WebhookDispatcher::dispatch()['failed']);

        $row = (new WebhookOutboxModel())->first();
        $this->assertSame('failed', $row['status']);
        $this->assertSame(1, (int) $row['attempts']);

        // still retriable → a later run succeeds
        WebhookDispatcher::$sender = static fn (): int => 200;
        $this->assertSame(1, WebhookDispatcher::dispatch()['sent']);
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
}

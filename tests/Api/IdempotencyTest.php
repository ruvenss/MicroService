<?php

declare(strict_types=1);

use Tests\Support\FeatureTestCase;

/**
 * @internal
 */
final class IdempotencyTest extends FeatureTestCase
{
    /** @var array<string, string> */
    private array $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auth = $this->authHeaders(['products:*']);
    }

    public function testRetryReplaysTheFirstResponse(): void
    {
        $headers = $this->auth + ['Idempotency-Key' => 'n8n-run-1'];
        $payload = ['sku' => 'IDEM-1', 'name' => 'Once', 'price' => '9.99'];

        $first = $this->withHeaders($headers)->withBodyFormat('json')->post('api/v1/products', $payload);
        $first->assertStatus(201);
        $id = json_decode((string) $first->response()->getBody(), true)['data']['id'];

        $retry = $this->withHeaders($headers)->withBodyFormat('json')->post('api/v1/products', $payload);
        $retry->assertStatus(201);
        $this->assertSame('true', $retry->response()->getHeaderLine('Idempotency-Replayed'));
        $this->assertSame($id, json_decode((string) $retry->response()->getBody(), true)['data']['id']);

        // Only one row was actually created.
        $list = json_decode((string) $this->withHeaders($this->auth)->get('api/v1/products?filter[sku]=IDEM-1')->response()->getBody(), true);
        $this->assertSame(1, $list['meta']['pagination']['total']);
    }

    public function testRetryReplaysADeleteInsteadOfReExecuting(): void
    {
        // n8n commonly retries a DELETE after a network blip. With the same
        // Idempotency-Key the retry must REPLAY the first 204 — not re-run the delete
        // and 404 on the now-archived row. (The existing replay test only covers POST;
        // DELETE is where re-execution would visibly diverge: 204 replay vs 404 redo.)
        $id = json_decode((string) $this->withHeaders($this->auth)->withBodyFormat('json')
            ->post('api/v1/products', ['sku' => 'IDEM-DEL', 'name' => 'X', 'price' => '1.00'])
            ->response()->getBody(), true)['data']['id'];

        $delHeaders = $this->auth + ['Idempotency-Key' => 'del-run-1'];

        $this->withHeaders($delHeaders)->delete("api/v1/products/{$id}")->assertStatus(204);

        $retry = $this->withHeaders($delHeaders)->delete("api/v1/products/{$id}");
        $retry->assertStatus(204); // replayed, NOT 404
        $this->assertSame('true', $retry->response()->getHeaderLine('Idempotency-Replayed'));

        // The contrast: without the key the retry re-executes and correctly 404s — the
        // row is already archived — proving the keyed retry above genuinely replayed
        // rather than re-running the delete.
        $this->withHeaders($this->auth)->delete("api/v1/products/{$id}")->assertStatus(404);
    }

    public function testLongPathWriteWithKeyIsNotMisclassifiedAsA409Conflict(): void
    {
        // A write to a >255-char path with an Idempotency-Key: the claim row's `path`
        // column is VARCHAR(255), so under STRICT_TRANS_TABLES an untruncated insert is
        // rejected — and claim()'s catch (which assumes any failure is a concurrent
        // duplicate-key) would answer 409 "in progress" AND stay stuck there on every
        // retry, so the request could NEVER succeed. It must resolve normally instead.
        db_connect()->query("SET SESSION sql_mode = 'STRICT_TRANS_TABLES'");

        $headers  = $this->auth + ['Idempotency-Key' => 'longpath-1'];
        $longPath = 'api/v1/products/' . str_repeat('a', 300); // ~316 chars, no such row

        $first = $this->withHeaders($headers)->delete($longPath);
        $this->assertSame(404, $first->response()->getStatusCode(), 'a missing row is 404, not a 409 idempotency conflict');

        // And a retry is not wedged at 409 either (replays the stored 404).
        $retry = $this->withHeaders($headers)->delete($longPath);
        $this->assertNotSame(409, $retry->response()->getStatusCode());
    }

    public function testSameKeyDifferentRequestReturns422(): void
    {
        $headers = $this->auth + ['Idempotency-Key' => 'n8n-run-2'];

        $this->withHeaders($headers)->withBodyFormat('json')->post('api/v1/products', ['sku' => 'IDEM-2', 'name' => 'A', 'price' => '1.00']);

        $this->withHeaders($headers)->withBodyFormat('json')
            ->post('api/v1/products', ['sku' => 'IDEM-3', 'name' => 'B', 'price' => '2.00'])
            ->assertStatus(422);
    }

    public function testWithoutKeyEachRequestExecutes(): void
    {
        $this->withHeaders($this->auth)->withBodyFormat('json')->post('api/v1/products', ['sku' => 'NK-1', 'name' => 'A', 'price' => '1.00']);
        $this->withHeaders($this->auth)->withBodyFormat('json')->post('api/v1/products', ['sku' => 'NK-2', 'name' => 'B', 'price' => '1.00']);

        $list = json_decode((string) $this->withHeaders($this->auth)->get('api/v1/products')->response()->getBody(), true);
        $this->assertSame(2, $list['meta']['pagination']['total']);
    }

    public function testInvalidKeyReturns400(): void
    {
        $this->withHeaders($this->auth + ['Idempotency-Key' => 'not a valid key!'])
            ->withBodyFormat('json')
            ->post('api/v1/products', ['sku' => 'IK-1', 'name' => 'A', 'price' => '1.00'])
            ->assertStatus(400);
    }

    public function testIdempotencyKeysAreIsolatedPerApiKey(): void
    {
        // Two DIFFERENT API keys reuse the same Idempotency-Key value. Key B must
        // NOT replay key A's response, nor be rejected as a "different request":
        // the key is scoped per authenticated API key (no cross-tenant leak).
        $keyA = ['Authorization' => 'Bearer ' . $this->makeKey(['products:*'])];
        $keyB = ['Authorization' => 'Bearer ' . $this->makeKey(['products:*'])];
        $idem = ['Idempotency-Key' => 'shared-run'];

        $this->withHeaders($keyA + $idem)->withBodyFormat('json')
            ->post('api/v1/products', ['sku' => 'ISO-A', 'name' => 'A', 'price' => '1.00'])
            ->assertStatus(201);

        $this->withHeaders($keyB + $idem)->withBodyFormat('json')
            ->post('api/v1/products', ['sku' => 'ISO-B', 'name' => 'B', 'price' => '2.00'])
            ->assertStatus(201);

        // Both executed independently (key B did not replay key A): two distinct
        // records exist. If the key were global, B would have replayed A's 201 and
        // created nothing, leaving only ISO-A.
        $skus = array_column(
            json_decode((string) $this->withHeaders($keyA)->get('api/v1/products?perPage=100')->response()->getBody(), true)['data'],
            'sku',
        );
        $this->assertContains('ISO-A', $skus);
        $this->assertContains('ISO-B', $skus);
    }

    public function testConcurrentRetryWhileOriginalInFlightReturns409(): void
    {
        // n8n's timeout-retry overlap: a retry arrives while the original request is
        // still executing. The claim is atomic, so the retry must NOT run the write a
        // second time — it gets 409 in-progress. Simulated by flipping the recorded
        // row back to a recent "pending" claim (its hash already matches the request).
        $headers = $this->auth + ['Idempotency-Key' => 'inflight'];
        $payload = ['sku' => 'INFLIGHT-1', 'name' => 'A', 'price' => '1.00'];

        $this->withHeaders($headers)->withBodyFormat('json')->post('api/v1/products', $payload)->assertStatus(201);

        db_connect()->table('idempotency_keys')->where('idem_key', 'inflight')
            ->update(['response_status' => 0, 'created_at' => date('Y-m-d H:i:s')]); // recent pending

        $retry = $this->withHeaders($headers)->withBodyFormat('json')->post('api/v1/products', $payload);
        $retry->assertStatus(409);
        $this->assertNotSame('', $retry->response()->getHeaderLine('Retry-After'));
    }

    public function testStalePendingClaimIsTakenOver(): void
    {
        // If the original request died without finalising its claim, the pending row
        // must not block the key forever. A claim older than the stale window is taken
        // over and the write re-runs (here the re-run hits the unique sku from the first
        // create → 422, proving it executed rather than 409'ing or replaying).
        $headers = $this->auth + ['Idempotency-Key' => 'stale'];
        $payload = ['sku' => 'STALE-1', 'name' => 'A', 'price' => '1.00'];

        $this->withHeaders($headers)->withBodyFormat('json')->post('api/v1/products', $payload)->assertStatus(201);

        db_connect()->table('idempotency_keys')->where('idem_key', 'stale')
            ->update(['response_status' => 0, 'created_at' => date('Y-m-d H:i:s', time() - 120)]); // stale pending

        $this->withHeaders($headers)->withBodyFormat('json')
            ->post('api/v1/products', $payload)
            ->assertStatus(422);
    }

    public function testFailedRequestReleasesTheClaimSoItStaysRetryable(): void
    {
        // A non-2xx response must drop the claim, so a legitimate retry is not blocked.
        $headers = $this->auth + ['Idempotency-Key' => 'retry-me'];

        // Invalid body → 422 validation; the claim is inserted then released.
        $this->withHeaders($headers)->withBodyFormat('json')
            ->post('api/v1/products', ['sku' => 'REL-1', 'name' => 'A']) // missing required price
            ->assertStatus(422);

        $this->assertSame(
            0,
            db_connect()->table('idempotency_keys')->where('idem_key', 'retry-me')->countAllResults(),
            'the failed request must leave no lingering claim',
        );

        // A corrected retry with the same key executes normally.
        $this->withHeaders($headers)->withBodyFormat('json')
            ->post('api/v1/products', ['sku' => 'REL-1', 'name' => 'A', 'price' => '1.00'])
            ->assertStatus(201);
    }

    public function testExpiredKeyIsNotReplayed(): void
    {
        $headers = $this->auth + ['Idempotency-Key' => 'expiring'];
        $payload = ['sku' => 'EXP-1', 'name' => 'A', 'price' => '1.00'];

        $this->withHeaders($headers)->withBodyFormat('json')->post('api/v1/products', $payload)->assertStatus(201);

        // Age the stored key past its TTL.
        db_connect()->table('idempotency_keys')->where('idem_key', 'expiring')
            ->update(['expires_at' => date('Y-m-d H:i:s', time() - 1)]);

        // Same key + same body now re-executes → the unique sku collides → 422
        // validation (proving it did NOT replay the stored 201).
        $this->withHeaders($headers)->withBodyFormat('json')
            ->post('api/v1/products', $payload)
            ->assertStatus(422);
    }
}

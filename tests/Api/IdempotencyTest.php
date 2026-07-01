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
}

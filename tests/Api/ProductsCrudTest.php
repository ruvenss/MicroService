<?php

declare(strict_types=1);

use Tests\Support\FeatureTestCase;

/**
 * End-to-end CRUD for the sample `products` resource, exercising the generic
 * engine (registry → model → controller) against the real test database.
 *
 * @internal
 */
final class ProductsCrudTest extends FeatureTestCase
{
    /** @var array<string, string> */
    private array $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auth = $this->authHeaders(['products:*']);
    }

    private function create(array $payload): array
    {
        return json_decode((string) $this->withHeaders($this->auth)
            ->withBodyFormat('json')->post('api/v1/products', $payload)
            ->response()->getBody(), true);
    }

    public function testCreateReturns201WithLocationAndData(): void
    {
        $result = $this->withHeaders($this->auth)->withBodyFormat('json')
            ->post('api/v1/products', ['sku' => 'SKU-1', 'name' => 'Widget', 'price' => '9.99']);

        $result->assertStatus(201);
        $this->assertStringContainsString('/api/v1/products/', $result->response()->getHeaderLine('Location'));

        $json = json_decode((string) $result->response()->getBody(), true);
        $this->assertSame('SKU-1', $json['data']['sku']);
        $this->assertSame('Widget', $json['data']['name']);
    }

    public function testCreateThenShow(): void
    {
        $id = $this->create(['sku' => 'SKU-2', 'name' => 'Gadget', 'price' => '1.50'])['data']['id'];

        $result = $this->withHeaders($this->auth)->get("api/v1/products/{$id}");
        $result->assertStatus(200);
        $this->assertSame('Gadget', json_decode((string) $result->response()->getBody(), true)['data']['name']);
    }

    public function testListReturnsEnvelopeWithPagination(): void
    {
        foreach (['A', 'B', 'C'] as $i => $sku) {
            $this->create(['sku' => "SKU-{$sku}", 'name' => "P{$i}", 'price' => '1.00']);
        }

        $json = json_decode((string) $this->withHeaders($this->auth)->get('api/v1/products')->response()->getBody(), true);

        $this->assertCount(3, $json['data']);
        $this->assertSame(3, $json['meta']['pagination']['total']);
        $this->assertSame(1, $json['meta']['pagination']['page']);
    }

    public function testValidationReturns422ProblemJson(): void
    {
        $result = $this->withHeaders($this->auth)->withBodyFormat('json')->post('api/v1/products', []);

        $result->assertStatus(422);
        $json = json_decode((string) $result->response()->getBody(), true);
        $this->assertSame('Unprocessable Entity', $json['title']);
        $this->assertArrayHasKey('sku', $json['errors']);
        $this->assertArrayHasKey('name', $json['errors']);
    }

    public function testUpdateModifiesResource(): void
    {
        $id = $this->create(['sku' => 'SKU-U', 'name' => 'Before', 'price' => '2.00'])['data']['id'];

        $result = $this->withHeaders($this->auth)->withBodyFormat('json')
            ->patch("api/v1/products/{$id}", ['name' => 'After']);
        $result->assertStatus(200);
        $this->assertSame('After', json_decode((string) $result->response()->getBody(), true)['data']['name']);
    }

    public function testDeleteThenShowReturns404(): void
    {
        $id = $this->create(['sku' => 'SKU-D', 'name' => 'Doomed', 'price' => '3.00'])['data']['id'];

        $this->withHeaders($this->auth)->delete("api/v1/products/{$id}")->assertStatus(204);
        $this->withHeaders($this->auth)->get("api/v1/products/{$id}")->assertStatus(404);
    }

    public function testUnknownResourceReturnsNeutral404(): void
    {
        $result = $this->withHeaders($this->authHeaders(['*:read']))->get('api/v1/widgets');

        $result->assertStatus(404);
        $this->assertStringContainsString('application/problem+json', $result->response()->getHeaderLine('Content-Type'));
    }
}

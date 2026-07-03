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
        $location = $result->response()->getHeaderLine('Location');
        $this->assertStringContainsString('/api/v1/products/', $location);
        // Clean URL — must not leak the PHP front controller (indexPage is empty
        // because Apache rewrites index.php away).
        $this->assertStringNotContainsString('index.php', $location);

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

    public function testNullOptionalFieldUsesTheDefaultNot500(): void
    {
        // n8n emits null for an unmapped optional field. An explicit null must not be
        // written into the NOT-NULL `status` column (which 500'd); it is treated as
        // "not provided", so the column default (`active`) applies.
        $result = $this->withHeaders($this->auth)->withBodyFormat('json')
            ->post('api/v1/products', ['sku' => 'NULL-1', 'name' => 'N', 'price' => '1.00', 'status' => null]);

        $result->assertStatus(201);
        $this->assertSame('active', json_decode((string) $result->response()->getBody(), true)['data']['status']);
    }

    public function testEmptyUpdateIsAClean422Not500(): void
    {
        // PATCH with no writable fields (empty body, or only unknown/null keys) must be a
        // clean 422, not the 500 CI4's empty-update throws.
        $id = $this->create(['sku' => 'EMPTY-1', 'name' => 'Before', 'price' => '1.00'])['data']['id'];

        $this->withHeaders($this->auth)->withBodyFormat('json')
            ->patch("api/v1/products/{$id}", [])->assertStatus(422);

        $this->withHeaders($this->auth)->withBodyFormat('json')
            ->patch("api/v1/products/{$id}", ['status' => null])->assertStatus(422);

        // unchanged
        $this->assertSame('Before', json_decode((string) $this->withHeaders($this->auth)->get("api/v1/products/{$id}")->response()->getBody(), true)['data']['name']);
    }

    public function testUpdateToACollidingUniqueValueIsAClean422(): void
    {
        // Updating a unique column to a value ANOTHER row already has is a validation
        // conflict, not a server error: the engine derives self-excluding is_unique for
        // the update from the create rules, so the collision is a clean 422 up front —
        // instead of slipping past validation and blowing up at the DB unique index (a
        // misleading 500, and — before the txn-rollback fix — a lock-wait hang).
        $a = $this->create(['sku' => 'LK-A', 'name' => 'A', 'price' => '1.00'])['data']['id'];
        $this->create(['sku' => 'LK-B', 'name' => 'B', 'price' => '1.00']);

        $result = $this->withHeaders($this->auth)->withBodyFormat('json')
            ->patch("api/v1/products/{$a}", ['sku' => 'LK-B']);
        $result->assertStatus(422);
        $this->assertArrayHasKey('sku', json_decode((string) $result->response()->getBody(), true)['errors']);

        // Keeping the row's OWN sku is fine (self is excluded), and a normal update works.
        $this->withHeaders($this->auth)->withBodyFormat('json')
            ->patch("api/v1/products/{$a}", ['sku' => 'LK-A', 'name' => 'Recovered'])->assertStatus(200);
        $this->assertSame('Recovered', json_decode((string) $this->withHeaders($this->auth)->get("api/v1/products/{$a}")->response()->getBody(), true)['data']['name']);
    }

    public function testUnknownResourceReturnsNeutral404(): void
    {
        $result = $this->withHeaders($this->authHeaders(['*:read']))->get('api/v1/widgets');

        $result->assertStatus(404);
        $this->assertStringContainsString('application/problem+json', $result->response()->getHeaderLine('Content-Type'));
    }
}

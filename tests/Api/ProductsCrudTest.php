<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * End-to-end CRUD for the sample `products` resource, exercising the generic
 * engine (registry → model → controller) against the real test database.
 *
 * @internal
 */
final class ProductsCrudTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $refresh   = true;

    public function testCreateReturns201WithLocationAndData(): void
    {
        $result = $this->withBodyFormat('json')->post('api/v1/products', [
            'sku'   => 'SKU-1',
            'name'  => 'Widget',
            'price' => '9.99',
        ]);

        $result->assertStatus(201);
        $this->assertStringContainsString('/api/v1/products/', $result->response()->getHeaderLine('Location'));

        $json = json_decode((string) $result->response()->getBody(), true);
        $this->assertSame('SKU-1', $json['data']['sku']);
        $this->assertSame('Widget', $json['data']['name']);
    }

    public function testCreateThenShow(): void
    {
        $created = json_decode((string) $this->withBodyFormat('json')
            ->post('api/v1/products', ['sku' => 'SKU-2', 'name' => 'Gadget', 'price' => '1.50'])
            ->response()->getBody(), true);
        $id = $created['data']['id'];

        $result = $this->get("api/v1/products/{$id}");
        $result->assertStatus(200);
        $this->assertSame('Gadget', json_decode((string) $result->response()->getBody(), true)['data']['name']);
    }

    public function testListReturnsEnvelopeWithPagination(): void
    {
        foreach (['A', 'B', 'C'] as $i => $sku) {
            $this->withBodyFormat('json')->post('api/v1/products', [
                'sku' => "SKU-{$sku}", 'name' => "P{$i}", 'price' => '1.00',
            ]);
        }

        $json = json_decode((string) $this->get('api/v1/products')->response()->getBody(), true);

        $this->assertCount(3, $json['data']);
        $this->assertSame(3, $json['meta']['pagination']['total']);
        $this->assertSame(1, $json['meta']['pagination']['page']);
    }

    public function testValidationReturns422ProblemJson(): void
    {
        $result = $this->withBodyFormat('json')->post('api/v1/products', []);

        $result->assertStatus(422);
        $json = json_decode((string) $result->response()->getBody(), true);
        $this->assertSame('Unprocessable Entity', $json['title']);
        $this->assertArrayHasKey('sku', $json['errors']);
        $this->assertArrayHasKey('name', $json['errors']);
    }

    public function testUpdateModifiesResource(): void
    {
        $id = json_decode((string) $this->withBodyFormat('json')
            ->post('api/v1/products', ['sku' => 'SKU-U', 'name' => 'Before', 'price' => '2.00'])
            ->response()->getBody(), true)['data']['id'];

        $result = $this->withBodyFormat('json')->patch("api/v1/products/{$id}", ['name' => 'After']);
        $result->assertStatus(200);
        $this->assertSame('After', json_decode((string) $result->response()->getBody(), true)['data']['name']);
    }

    public function testDeleteThenShowReturns404(): void
    {
        $id = json_decode((string) $this->withBodyFormat('json')
            ->post('api/v1/products', ['sku' => 'SKU-D', 'name' => 'Doomed', 'price' => '3.00'])
            ->response()->getBody(), true)['data']['id'];

        $this->delete("api/v1/products/{$id}")->assertStatus(204);
        $this->get("api/v1/products/{$id}")->assertStatus(404);
    }

    public function testUnknownResourceReturnsNeutral404(): void
    {
        $result = $this->get('api/v1/widgets');

        $result->assertStatus(404);
        $this->assertStringContainsString('application/problem+json', $result->response()->getHeaderLine('Content-Type'));
    }
}

<?php

declare(strict_types=1);

use Tests\Support\FeatureTestCase;

/**
 * @internal
 */
final class DiscoveryTest extends FeatureTestCase
{
    public function testRequiresAuthentication(): void
    {
        $this->get('api/v1/_resources')->assertStatus(401);
    }

    public function testListsRegisteredResourcesWhenAuthenticated(): void
    {
        $result = $this->withHeaders($this->authHeaders(['*:read']))->get('api/v1/_resources');
        $result->assertStatus(200);

        $json     = json_decode((string) $result->response()->getBody(), true);
        $products = null;
        foreach ($json['data'] as $entry) {
            if ($entry['resource'] === 'products') {
                $products = $entry;
            }
        }

        $this->assertNotNull($products);
        $this->assertSame('/api/v1/products', $products['endpoint']);
        $this->assertContains('status', $products['filterable']);
        $this->assertContains('eq', $products['operators']);
        $this->assertContains('id', $products['fields']);
    }

    public function testAdvertisesUpsertKeyAndFieldSchema(): void
    {
        $result = $this->withHeaders($this->authHeaders(['*:read']))->get('api/v1/_resources');
        $json   = json_decode((string) $result->response()->getBody(), true);

        $products = null;
        foreach ($json['data'] as $entry) {
            if ($entry['resource'] === 'products') {
                $products = $entry;
            }
        }

        // n8n can discover that PUT upsert is available and by which key.
        $this->assertSame('sku', $products['upsertKey']);

        // Per-field input schema: required flags + JSON types (for form-building).
        $this->assertTrue($products['schema']['sku']['required']);        // required on create
        $this->assertFalse($products['schema']['status']['required']);    // permit_empty
        $this->assertSame('string', $products['schema']['sku']['type']);
        $this->assertSame('float', $products['schema']['price']['type']); // from the declared cast

        // Pagination + bulk limits, so an n8n workflow can size its page/batch calls
        // to fit instead of discovering the caps by hitting a 4xx.
        $this->assertSame(100, $products['perPage']['max']);
        $this->assertSame(\App\Controllers\Api\ResourceController::BULK_MAX, $products['bulkMax']);
    }

    public function testAdvertisesEnumChoicesForConstrainedFields(): void
    {
        $result = $this->withHeaders($this->authHeaders(['*:read']))->get('api/v1/_resources');
        $json   = json_decode((string) $result->response()->getBody(), true);

        $products = null;
        foreach ($json['data'] as $entry) {
            if ($entry['resource'] === 'products') {
                $products = $entry;
            }
        }

        // A constrained field advertises its allowed values (from in_list[...]), so an
        // n8n node building a create request from this live call knows the choices —
        // consistent with what OpenAPI/Postman expose, not a bare "string".
        $this->assertSame(['active', 'archived'], $products['schema']['status']['enum']);

        // Unconstrained fields carry no enum key (kept lean).
        $this->assertArrayNotHasKey('enum', $products['schema']['sku']);
        $this->assertArrayNotHasKey('enum', $products['schema']['price']);
    }
}

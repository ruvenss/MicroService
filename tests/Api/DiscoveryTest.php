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
    }
}

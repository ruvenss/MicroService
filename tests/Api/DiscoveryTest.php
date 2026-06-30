<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class DiscoveryTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testListsRegisteredResources(): void
    {
        $result = $this->get('api/v1/_resources');
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
}

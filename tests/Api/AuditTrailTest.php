<?php

declare(strict_types=1);

use App\Models\AuditLogModel;
use Tests\Support\FeatureTestCase;

/**
 * @internal
 */
final class AuditTrailTest extends FeatureTestCase
{
    private function createProduct(array $headers, string $name = 'Name'): string
    {
        return (string) json_decode((string) $this->withHeaders($headers)->withBodyFormat('json')
            ->post('api/v1/products', ['sku' => 'SKU-' . uniqid(), 'name' => $name, 'price' => '1.00'])
            ->response()->getBody(), true)['data']['id'];
    }

    public function testCreateIsAudited(): void
    {
        $this->createProduct($this->authHeaders(['products:*']));

        $rows = (new AuditLogModel())->where('action', 'create')->where('resource', 'products')->findAll();
        $this->assertNotEmpty($rows);
        $this->assertNotNull($rows[0]['after_json']);
        $this->assertNull($rows[0]['before_json']);
    }

    public function testUpdateIsAuditedWithChangedFields(): void
    {
        $headers = $this->authHeaders(['products:*']);
        $id      = $this->createProduct($headers, 'Old');

        $this->withHeaders($headers)->withBodyFormat('json')->patch("api/v1/products/{$id}", ['name' => 'New']);

        $rows = (new AuditLogModel())->where('action', 'update')->where('record_id', (string) $id)->findAll();
        $this->assertNotEmpty($rows);
        $this->assertContains('name', json_decode((string) $rows[0]['changed_json'], true));
    }

    public function testAuditEndpointRequiresAuditScope(): void
    {
        $this->withHeaders($this->authHeaders(['products:read']))->get('api/v1/_audit')->assertStatus(403);
    }

    public function testAuditEndpointListsEntries(): void
    {
        $headers = $this->authHeaders(['products:*', 'audit:read']);
        $this->createProduct($headers);

        $json = json_decode((string) $this->withHeaders($headers)->get('api/v1/_audit')->response()->getBody(), true);

        $this->assertNotEmpty($json['data']);
        $this->assertSame('create', $json['data'][0]['action']);
    }
}

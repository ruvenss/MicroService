<?php

declare(strict_types=1);

use App\Core\Plugin\ResourceEvent;
use CodeIgniter\Events\Events;
use Tests\Support\FeatureTestCase;

/**
 * Proves the generic engine fires plugin-extensible lifecycle events. Listeners
 * are registered per test and removed in tearDown for isolation.
 *
 * @internal
 */
final class ResourceEventsTest extends FeatureTestCase
{
    protected function tearDown(): void
    {
        Events::removeAllListeners('resource.beforeSave');
        Events::removeAllListeners('resource.serialize');
        Events::removeAllListeners('resource.beforeQuery');
        parent::tearDown();
    }

    private function create(array $payload): array
    {
        return json_decode((string) $this->withHeaders($this->authHeaders(['products:*']))
            ->withBodyFormat('json')->post('api/v1/products', $payload)
            ->response()->getBody(), true);
    }

    public function testBeforeSaveMutatesThePayload(): void
    {
        Events::on('resource.beforeSave', static function (ResourceEvent $e): void {
            if ($e->action === 'create') {
                $e->data['status'] = 'archived';
            }
        });

        // No status sent; the DB default is 'active', so 'archived' proves the hook ran.
        $body = $this->create(['sku' => 'EV-1', 'name' => 'N', 'price' => '1.00']);

        $this->assertSame('archived', $body['data']['status']);
    }

    public function testSerializeTransformsTheOutput(): void
    {
        Events::on('resource.serialize', static function (ResourceEvent $e): void {
            $e->row['computed'] = strtoupper((string) ($e->row['sku'] ?? ''));
        });

        $body = $this->create(['sku' => 'ev-2', 'name' => 'N', 'price' => '1.00']);

        $this->assertSame('EV-2', $body['data']['computed']);
    }

    public function testBeforeQueryScopesTheList(): void
    {
        Events::on('resource.beforeQuery', static function (ResourceEvent $e): void {
            $e->model?->where('status', 'active');
        });

        $this->create(['sku' => 'EV-A', 'name' => 'N', 'price' => '1.00', 'status' => 'active']);
        $this->create(['sku' => 'EV-B', 'name' => 'N', 'price' => '1.00', 'status' => 'archived']);

        $json = json_decode((string) $this->withHeaders($this->authHeaders(['products:read']))
            ->get('api/v1/products')->response()->getBody(), true);

        $this->assertCount(1, $json['data']);
        $this->assertSame('active', $json['data'][0]['status']);
    }
}

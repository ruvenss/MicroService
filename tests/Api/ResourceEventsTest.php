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
        Events::removeAllListeners('resource.beforeDelete');
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

    public function testBeforeDeleteCanVetoADelete(): void
    {
        // A plugin refuses to delete an 'archived' product (its stand-in for "locked").
        Events::on('resource.beforeDelete', static function (ResourceEvent $e): void {
            if (($e->row['status'] ?? null) === 'archived') {
                $e->cancel('This product is archived and cannot be deleted.');
            }
        });

        $auth = $this->authHeaders(['products:*']);
        $id   = $this->create(['sku' => 'EV-LOCK', 'name' => 'N', 'price' => '1.00', 'status' => 'archived'])['data']['id'];

        $result = $this->withHeaders($auth)->delete("api/v1/products/{$id}");
        $result->assertStatus(409);
        $this->assertSame('This product is archived and cannot be deleted.', json_decode((string) $result->response()->getBody(), true)['detail']);

        // The row survives — the veto ran before any archive/delete.
        $this->withHeaders($auth)->get("api/v1/products/{$id}")->assertStatus(200);
    }

    public function testBeforeDeleteAllowsUnvetoedDeletes(): void
    {
        Events::on('resource.beforeDelete', static function (ResourceEvent $e): void {
            if (($e->row['status'] ?? null) === 'archived') {
                $e->cancel('archived');
            }
        });

        $auth = $this->authHeaders(['products:*']);
        $id   = $this->create(['sku' => 'EV-OPEN', 'name' => 'N', 'price' => '1.00', 'status' => 'active'])['data']['id'];

        $this->withHeaders($auth)->delete("api/v1/products/{$id}")->assertStatus(204);
        $this->withHeaders($auth)->get("api/v1/products/{$id}")->assertStatus(404);
    }

    public function testBeforeDeleteVetoAbortsAWholeBulkBatch(): void
    {
        Events::on('resource.beforeDelete', static function (ResourceEvent $e): void {
            if (($e->row['sku'] ?? null) === 'BULK-LOCK') {
                $e->cancel('locked');
            }
        });

        $auth = $this->authHeaders(['products:*']);
        $a    = $this->create(['sku' => 'BULK-OK', 'name' => 'N', 'price' => '1.00'])['data']['id'];
        $b    = $this->create(['sku' => 'BULK-LOCK', 'name' => 'N', 'price' => '1.00'])['data']['id'];

        $this->withHeaders($auth)->withBodyFormat('json')
            ->delete('api/v1/products', ['ids' => [$a, $b]])
            ->assertStatus(409);

        // All-or-nothing: the un-vetoed row is untouched too.
        $this->withHeaders($auth)->get("api/v1/products/{$a}")->assertStatus(200);
        $this->withHeaders($auth)->get("api/v1/products/{$b}")->assertStatus(200);
    }
}

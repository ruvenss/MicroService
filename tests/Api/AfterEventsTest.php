<?php

declare(strict_types=1);

use App\Core\Plugin\ResourceEvent;
use CodeIgniter\Events\Events;
use Tests\Support\FeatureTestCase;

/**
 * Post-commit lifecycle events let plugins react to durable changes (e.g. push
 * a webhook to n8n). Verified by capturing events with test listeners.
 *
 * @internal
 */
final class AfterEventsTest extends FeatureTestCase
{
    /** @var array<string, string> */
    private array $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auth = $this->authHeaders(['products:*']);
    }

    protected function tearDown(): void
    {
        foreach (['afterCreate', 'afterUpdate', 'afterDelete'] as $e) {
            Events::removeAllListeners('resource.' . $e);
        }
        parent::tearDown();
    }

    private function create(array $payload): array
    {
        return json_decode((string) $this->withHeaders($this->auth)->withBodyFormat('json')
            ->post('api/v1/products', $payload)->response()->getBody(), true);
    }

    public function testAfterCreateFires(): void
    {
        $captured = null;
        Events::on('resource.afterCreate', static function (ResourceEvent $e) use (&$captured): void {
            $captured = $e;
        });

        $this->create(['sku' => 'AE-1', 'name' => 'Created', 'price' => '1.00']);

        $this->assertInstanceOf(ResourceEvent::class, $captured);
        $this->assertSame('products', $captured->resource);
        $this->assertSame('afterCreate', $captured->action);
        $this->assertSame('AE-1', $captured->row['sku']);
        $this->assertIsInt($captured->row['id']); // presented (cast) row
    }

    public function testAfterUpdateCarriesBeforeAndAfter(): void
    {
        $id = $this->create(['sku' => 'AE-2', 'name' => 'Before', 'price' => '1.00'])['data']['id'];

        $seen = null;
        Events::on('resource.afterUpdate', static function (ResourceEvent $e) use (&$seen): void {
            $seen = $e;
        });

        $this->withHeaders($this->auth)->withBodyFormat('json')->patch("api/v1/products/{$id}", ['name' => 'After']);

        $this->assertNotNull($seen);
        $this->assertSame('After', $seen->row['name']);
        $this->assertSame('Before', $seen->data['name']); // prior state
    }

    public function testAfterDeleteFiresWithTheDeletedRow(): void
    {
        $id = $this->create(['sku' => 'AE-3', 'name' => 'Doomed', 'price' => '1.00'])['data']['id'];

        $seen = null;
        Events::on('resource.afterDelete', static function (ResourceEvent $e) use (&$seen): void {
            $seen = $e;
        });

        $this->withHeaders($this->auth)->delete("api/v1/products/{$id}")->assertStatus(204);

        $this->assertNotNull($seen);
        $this->assertSame('AE-3', $seen->row['sku']);
    }
}

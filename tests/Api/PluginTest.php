<?php

declare(strict_types=1);

use App\Core\Plugin\PluginManager;
use App\Libraries\ResourceRegistry;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The sealed-core model: business resources are contributed by plugins, not by
 * app/. Proven against the sample plugin (plugins/Sample/Catalog).
 *
 * @internal
 */
final class PluginTest extends CIUnitTestCase
{
    public function testDiscoversSamplePlugin(): void
    {
        $names = array_map(static fn (array $m): string => $m['name'], PluginManager::instance()->manifests());

        $this->assertContains('Sample/Catalog', $names);
    }

    public function testPluginContributesProductsResource(): void
    {
        $this->assertArrayHasKey('products', PluginManager::instance()->resources());
    }

    public function testRegistryResolvesPluginResource(): void
    {
        $registry = ResourceRegistry::instance();

        $this->assertTrue($registry->has('products'));
        $this->assertContains('products', $registry->slugs());
        $this->assertSame('products', $registry->get('products')?->slug);
    }

    public function testCoreShipsNoBusinessResources(): void
    {
        $this->assertSame([], config('Resources')->resources);
    }
}

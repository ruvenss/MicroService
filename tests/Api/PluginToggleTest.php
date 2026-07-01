<?php

declare(strict_types=1);

use App\Core\Plugin\PluginManager;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * plugin:enable / plugin:disable and the catalog they build on. Uses a throwaway
 * manifest under plugins/ (no Plugin class, so discovery ignores it) and removes
 * it afterwards, so the real Sample/Catalog plugin is never touched.
 *
 * @internal
 */
final class PluginToggleTest extends CIUnitTestCase
{
    private string $dir;
    private string $manifest;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir      = ROOTPATH . 'plugins/ToggleFixture/Demo';
        $this->manifest = $this->dir . '/plugin.json';

        if (! is_dir($this->dir)) {
            mkdir($this->dir, 0777, true);
        }
        $this->writeManifest(true);
        PluginManager::reset();
    }

    protected function tearDown(): void
    {
        @unlink($this->manifest);
        @rmdir($this->dir);
        @rmdir(ROOTPATH . 'plugins/ToggleFixture');
        PluginManager::reset();
        parent::tearDown();
    }

    private function writeManifest(bool $enabled): void
    {
        file_put_contents($this->manifest, json_encode([
            'name'      => 'ToggleFixture/Demo',
            'version'   => '2.1.0',
            'namespace' => 'Plugins\\ToggleFixture\\Demo',
            'provides'  => ['resources' => ['widgets']],
            'enabled'   => $enabled,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    private function manifestEnabled(): bool
    {
        return json_decode((string) file_get_contents($this->manifest), true)['enabled'];
    }

    public function testCatalogSeesEnabledAndDisabledPlugins(): void
    {
        $byName = array_column(PluginManager::catalog(), null, 'name');

        $this->assertArrayHasKey('ToggleFixture/Demo', $byName);
        $this->assertTrue($byName['ToggleFixture/Demo']['enabled']);
        $this->assertSame(['widgets'], $byName['ToggleFixture/Demo']['resources']);
        // The real sample plugin is still listed alongside it.
        $this->assertArrayHasKey('Sample/Catalog', $byName);
    }

    public function testSetEnabledFlipsTheManifestAndIsCaseInsensitive(): void
    {
        $this->assertNotNull(PluginManager::setEnabled('ToggleFixture/Demo', false));
        $this->assertFalse($this->manifestEnabled());

        // case-insensitive match, and re-enable
        $this->assertNotNull(PluginManager::setEnabled('togglefixture/demo', true));
        $this->assertTrue($this->manifestEnabled());
    }

    public function testUnknownPluginReturnsNull(): void
    {
        $this->assertNull(PluginManager::setEnabled('No/Such', false));
    }

    public function testDisableCommandFlipsManifest(): void
    {
        ob_start();
        command('plugin:disable ToggleFixture/Demo');
        ob_end_clean();

        $this->assertFalse($this->manifestEnabled());
    }

    public function testEnableCommandFlipsManifest(): void
    {
        $this->writeManifest(false);

        ob_start();
        command('plugin:enable ToggleFixture/Demo');
        ob_end_clean();

        $this->assertTrue($this->manifestEnabled());
    }
}

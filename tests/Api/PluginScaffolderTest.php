<?php

declare(strict_types=1);

use App\Core\Plugin\PluginScaffolder;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class PluginScaffolderTest extends CIUnitTestCase
{
    public function testParsesVendorName(): void
    {
        $this->assertSame(['vendor' => 'Acme', 'name' => 'Billing'], PluginScaffolder::parse('Acme/Billing'));
    }

    public function testRejectsInvalidName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PluginScaffolder::parse('acme/billing');
    }

    public function testGeneratesASelfContainedPlugin(): void
    {
        $files = PluginScaffolder::files('Acme/Widgets');
        $paths = array_keys($files);

        $this->assertContains('Acme/Widgets/plugin.json', $paths);
        $this->assertContains('Acme/Widgets/Plugin.php', $paths);
        $this->assertContains('Acme/Widgets/README.md', $paths);
        $this->assertNotEmpty(preg_grep('#Acme/Widgets/Database/Migrations/.*_CreateWidgets\.php#', $paths));

        $manifest = json_decode($files['Acme/Widgets/plugin.json'], true);
        $this->assertSame('Plugins\\Acme\\Widgets', $manifest['namespace']);
        $this->assertContains('widgets', $manifest['provides']['resources']);

        $this->assertStringContainsString('namespace Plugins\\Acme\\Widgets;', $files['Acme/Widgets/Plugin.php']);
        $this->assertStringContainsString("registerResource('widgets'", $files['Acme/Widgets/Plugin.php']);
        $this->assertStringContainsString("createTable('widgets'", implode('', $files));
    }

    public function testScaffoldedResourceDeclaresTypeCastsSoOutputMatchesTheContract(): void
    {
        // MySQLi returns every column as a string. Without output casts a new resource
        // emits `id` as "1" and timestamps in naive `Y-m-d H:i:s` — diverging from every
        // other resource and breaking the "parse every response/webhook with one rule"
        // promise for n8n. The scaffold must ship casts for id + the managed timestamps.
        $plugin = PluginScaffolder::files('Acme/Widgets')['Acme/Widgets/Plugin.php'];

        $this->assertStringContainsString("'casts'", $plugin);
        $this->assertStringContainsString("'id' => 'int'", $plugin);
        $this->assertStringContainsString("'created_at' => 'datetime'", $plugin);
        $this->assertStringContainsString("'updated_at' => 'datetime'", $plugin);
    }

    public function testScaffoldedMigrationIndexesTheDefaultSortAndFilterColumns(): void
    {
        // The scaffold declares defaultSort '-created_at' and name as sortable/filterable.
        // Without matching indexes every list filesorts and deep pagination scans as the
        // table grows — contradicting the framework's index-backed pagination design. The
        // generated migration must index (created_at, id) for the keyset sort and `name`.
        $migration = '';
        foreach (PluginScaffolder::files('Acme/Widgets') as $path => $contents) {
            if (str_contains($path, 'Migrations')) {
                $migration = $contents;
            }
        }

        $this->assertNotSame('', $migration);
        $this->assertStringContainsString("addKey(['created_at', 'id']", $migration);
        $this->assertStringContainsString("addKey('name'", $migration);
    }
}

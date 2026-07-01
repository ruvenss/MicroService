<?php

declare(strict_types=1);

use App\Core\Seal\SealGuard;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Sealed-core invariant: the real core (app/) must never couple to a concrete
 * plugin, and the guard must catch it when it does.
 *
 * @internal
 */
final class SealGuardTest extends CIUnitTestCase
{
    private string $fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures = sys_get_temp_dir() . '/seal_' . bin2hex(random_bytes(4));
        mkdir($this->fixtures, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->fixtures . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->fixtures);
        parent::tearDown();
    }

    private function write(string $name, string $php): void
    {
        file_put_contents($this->fixtures . '/' . $name, "<?php\n" . $php);
    }

    public function testRealCoreHoldsTheSeal(): void
    {
        // The actual app/ must reference no concrete plugin.
        $this->assertSame([], SealGuard::violations(APPPATH));
    }

    public function testDetectsAConcreteUseImport(): void
    {
        $this->write('Bad.php', "use Plugins\\Sample\\Catalog\\Plugin;\n");

        $violations = SealGuard::violations($this->fixtures);
        $this->assertCount(1, $violations);
        $this->assertStringContainsString('Bad.php', $violations[0]['file']);
    }

    public function testDetectsAConcreteStringReference(): void
    {
        $this->write('Bad2.php', '$c = "Plugins\\\\Acme\\\\Billing\\\\Plugin";' . "\n");
        $this->assertCount(1, SealGuard::violations($this->fixtures));
    }

    public function testAllowsTheGenericMechanism(): void
    {
        // The plugin engine itself: a namespace built from variables, glob paths,
        // and prose — none of these couple the core to a specific plugin.
        $this->write('Ok.php', <<<'PHP'
            $namespace = "Plugins\\{$vendor}\\{$name}";
            $files = glob(ROOTPATH . 'plugins/*/*/plugin.json');
            // Plugins\<Vendor>\<Name> are discovered at runtime by manifest.
            PHP);

        $this->assertSame([], SealGuard::violations($this->fixtures));
    }

    public function testIgnoresNonPhpFiles(): void
    {
        file_put_contents($this->fixtures . '/notes.md', 'use Plugins\\Sample\\Catalog;');
        $this->assertSame([], SealGuard::violations($this->fixtures));
    }
}

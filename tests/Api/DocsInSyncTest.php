<?php

declare(strict_types=1);

use App\Libraries\Docs\DocsBundle;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Drift gate: the committed OpenAPI / Markdown / Postman artefacts must match
 * what the registry generates. If this fails, run `php spark docs:generate`.
 *
 * @internal
 */
final class DocsInSyncTest extends CIUnitTestCase
{
    public function testGeneratedDocsMatchCommittedFiles(): void
    {
        foreach (DocsBundle::artifacts() as $path => $expected) {
            $this->assertFileExists($path, "Missing generated doc: {$path}");
            $this->assertSame(
                $expected,
                file_get_contents($path),
                'Stale documentation: ' . basename($path) . ' — run `php spark docs:generate` and commit.',
            );
        }
    }
}

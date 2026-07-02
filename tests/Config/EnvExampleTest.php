<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Guards `.env.example` completeness so an operator can discover every knob they may
 * need to set. Two families are operator-tunable and easy to forget when adding one:
 * the webhook config (the n8n integration) and the retention windows. Both — plus the
 * external-DB host used by the docker-compose deployment — must appear in the template.
 *
 * @internal
 */
final class EnvExampleTest extends CIUnitTestCase
{
    private string $example;

    protected function setUp(): void
    {
        parent::setUp();
        $this->example = (string) file_get_contents(dirname(__DIR__, 2) . '/.env.example');
    }

    /**
     * Every `WEBHOOK_` / `RETENTION_` env var the app actually reads must be documented
     * in the template (commented is fine) — auto-discovered so a new one can't slip past.
     */
    public function testOperatorTunableEnvVarsAreDocumented(): void
    {
        // WEBHOOK_ / RETENTION_ identifiers only ever appear as env() key strings, so a
        // plain token match is enough (and avoids brittle quote-escaping in the pattern).
        $appDir = dirname(__DIR__, 2) . '/app';
        $vars   = [];
        foreach (self::phpFiles($appDir) as $file) {
            if (preg_match_all('/((?:WEBHOOK|RETENTION)_[A-Z_]+)/', (string) file_get_contents($file), $m) > 0) {
                $vars = [...$vars, ...$m[1]];
            }
        }
        $vars = array_unique($vars);
        $this->assertNotEmpty($vars, 'expected to find WEBHOOK_*/RETENTION_* reads in app/');

        foreach ($vars as $var) {
            $this->assertStringContainsString($var, $this->example, "{$var} is read by the app but undocumented in .env.example");
        }
    }

    public function testExternalDbHostIsDocumentedForDeployment(): void
    {
        // The docker-compose deployment targets an EXTERNAL DB via DB_HOST; without it in
        // the template an operator defaults to the dev host.docker.internal and fails.
        $this->assertStringContainsString('DB_HOST', $this->example);
    }

    /** @return list<string> */
    private static function phpFiles(string $dir): array
    {
        $files = [];
        $it    = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}

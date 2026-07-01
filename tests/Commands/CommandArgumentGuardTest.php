<?php

declare(strict_types=1);

use App\Models\ApiKeyModel;
use CodeIgniter\Test\Filters\CITestStreamFilter;
use Tests\Support\FeatureTestCase;

/**
 * Every CLI command must be non-interactive-safe. Previously a missing value fell
 * back to CLI::prompt(), whose CLI::input() returns false at EOF and TypeErrors —
 * so a scripted / non-TTY run (CI, `docker exec -T`, a provisioning script wiring
 * up n8n) crashed instead of behaving. Required identifiers now report a clean,
 * actionable error; commands with documented defaults run headlessly.
 *
 * @internal
 */
final class CommandArgumentGuardTest extends FeatureTestCase
{
    private function runCommand(string $command): string
    {
        CITestStreamFilter::registration();
        CITestStreamFilter::addOutputFilter();
        CITestStreamFilter::addErrorFilter();

        command($command);
        $output = CITestStreamFilter::$buffer;

        CITestStreamFilter::removeOutputFilter();
        CITestStreamFilter::removeErrorFilter();

        return $output;
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function requiredArgumentCommands(): iterable
    {
        yield 'key:rotate'     => ['key:rotate', 'Provide the key prefix'];
        yield 'key:revoke'     => ['key:revoke', 'Provide the key prefix'];
        yield 'plugin:enable'  => ['plugin:enable', 'Provide the plugin name'];
        yield 'plugin:disable' => ['plugin:disable', 'Provide the plugin name'];
    }

    /**
     * @dataProvider requiredArgumentCommands
     */
    public function testMissingRequiredArgumentIsGuidedNotAFatal(string $command, string $expected): void
    {
        $output = $this->runCommand($command);

        $this->assertStringContainsString($expected, $output);
        $this->assertStringNotContainsString('TypeError', $output);
    }

    public function testKeyCreateRunsHeadlesslyWithDocumentedDefaults(): void
    {
        $before = (new ApiKeyModel())->countAllResults();

        // No --name / --scopes: must mint a default key without prompting.
        $output = $this->runCommand('key:create --scopes products:read');

        $this->assertStringContainsString('API key created', $output);
        $this->assertStringNotContainsString('TypeError', $output);
        $this->assertSame($before + 1, (new ApiKeyModel())->countAllResults());
    }
}

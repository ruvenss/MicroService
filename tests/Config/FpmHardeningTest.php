<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Guards the production PHP hardening in the container config against regression
 * (these settings can only be verified end-to-end in the FPM container, not from
 * PHPUnit, so scan the shipped config files here).
 *
 * @internal
 */
final class FpmHardeningTest extends CIUnitTestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public function testFpmPoolDisablesShellAndProcessFunctions(): void
    {
        $conf = (string) file_get_contents($this->root() . '/docker/php/www.conf');

        $this->assertMatchesRegularExpression('/^\s*php_admin_value\[disable_functions\]\s*=/m', $conf);
        foreach (['exec', 'passthru', 'shell_exec', 'system', 'proc_open', 'popen'] as $fn) {
            $this->assertMatchesRegularExpression('/disable_functions\][^\n]*\b' . $fn . '\b/', $conf, "{$fn} must be disabled for FPM");
        }
    }

    public function testErrorsAreNeverDisplayed(): void
    {
        $ini = (string) file_get_contents($this->root() . '/docker/php/php.ini');

        $this->assertMatchesRegularExpression('/^\s*display_errors\s*=\s*Off/mi', $ini);
        $this->assertMatchesRegularExpression('/^\s*display_startup_errors\s*=\s*Off/mi', $ini);
        $this->assertMatchesRegularExpression('/^\s*expose_php\s*=\s*Off/mi', $ini);
    }
}

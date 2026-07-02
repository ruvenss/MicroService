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

    public function testFpmPoolDoesNotSetDisableFunctions(): void
    {
        // `php_admin_value[disable_functions]` is deliberately NOT set: on this PHP 8.5
        // build it corrupted the internal function table, making CodeIgniter's
        // render_backtrace run `var_export()` as `memory_reset_peak_usage()` and throw
        // ArgumentCountError while rendering ANY exception with arguments — masking every
        // real 500 in the logs and once leaking an open transaction (a 30s lock hang).
        // Re-adding it would reintroduce that, so guard the removal. (Equivalent shell/
        // process-exec hardening belongs at the container seccomp layer — see
        // ARCHITECTURE §18.3.) Only *active* (uncommented) directives count.
        $conf = (string) file_get_contents($this->root() . '/docker/php/www.conf');

        $this->assertDoesNotMatchRegularExpression(
            '/^\s*php_admin_value\[disable_functions\]\s*=/m',
            $conf,
            'disable_functions must stay unset — it corrupts render_backtrace on PHP 8.5 and masks all error logs.',
        );
    }

    public function testErrorsAreNeverDisplayed(): void
    {
        $ini = (string) file_get_contents($this->root() . '/docker/php/php.ini');

        $this->assertMatchesRegularExpression('/^\s*display_errors\s*=\s*Off/mi', $ini);
        $this->assertMatchesRegularExpression('/^\s*display_startup_errors\s*=\s*Off/mi', $ini);
        $this->assertMatchesRegularExpression('/^\s*expose_php\s*=\s*Off/mi', $ini);
    }
}

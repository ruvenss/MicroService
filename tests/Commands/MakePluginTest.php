<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\Filters\CITestStreamFilter;

/**
 * @internal
 */
final class MakePluginTest extends CIUnitTestCase
{
    public function testMissingNameFailsWithGuidanceNotAFatal(): void
    {
        // Previously a missing Vendor/Name fell back to CLI::prompt(), which faults
        // with a TypeError on EOF (a scripted / non-TTY run). It must now report a
        // clean, actionable message instead.
        CITestStreamFilter::registration();
        CITestStreamFilter::addOutputFilter();
        CITestStreamFilter::addErrorFilter();

        command('make:plugin'); // no argument

        $output = CITestStreamFilter::$buffer;

        CITestStreamFilter::removeOutputFilter();
        CITestStreamFilter::removeErrorFilter();

        $this->assertStringContainsString('Provide the plugin name', $output);
        $this->assertStringNotContainsString('TypeError', $output);
    }
}

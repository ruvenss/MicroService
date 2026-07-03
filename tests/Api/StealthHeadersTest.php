<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Verifies the Stealth filter scrubs engine fingerprints and adds the baseline
 * security headers. Uses an unknown path (404) so the assertion does not depend
 * on the database being reachable.
 *
 * @internal
 */
final class StealthHeadersTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testEngineFingerprintsAreHidden(): void
    {
        $result   = $this->get('definitely-not-a-real-path');
        $response = $result->response();

        // No PHP fingerprint, and the Server header is engine-neutral.
        $this->assertSame('', $response->getHeaderLine('X-Powered-By'));
        $this->assertSame('MicroService', $response->getHeaderLine('Server'));

        // The CodeIgniter DebugToolbar must not be wired into the chain.
        $this->assertSame('', $response->getHeaderLine('Debugbar-Time'));
        $this->assertSame('', $response->getHeaderLine('Debugbar-Link'));
    }

    public function testSecurityHeadersArePresent(): void
    {
        $response = $this->get('definitely-not-a-real-path')->response();

        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        $this->assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
        $this->assertStringContainsString("default-src 'none'", $response->getHeaderLine('Content-Security-Policy'));
    }
}

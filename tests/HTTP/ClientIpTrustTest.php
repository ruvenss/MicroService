<?php

declare(strict_types=1);

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;

/**
 * Client-IP trust model.
 *
 * The auth-failure brute-force throttle (ApiKeyAuth) and the audit/usage logs all
 * key on `$request->getIPAddress()`. That address MUST be the real connection
 * address (REMOTE_ADDR), never a client-supplied forwarding header — otherwise an
 * attacker could rotate `X-Forwarded-For` to mint a fresh throttle bucket per
 * request and brute-force keys without ever tripping the 429, and could poison the
 * audit trail with forged source IPs.
 *
 * The guarantee comes from `Config\App::$proxyIPs` being empty: CodeIgniter only
 * honours a forwarding header when REMOTE_ADDR matches a declared trusted proxy.
 * These tests pin that behaviour so enabling proxy trust later can't silently
 * reopen the bypass. (Also verified live against the container: 35 failures with a
 * different X-Forwarded-For each still 429'd after the 30/min threshold.)
 *
 * @internal
 */
final class ClientIpTrustTest extends CIUnitTestCase
{
    private function requestWithForwardedFor(App $config, string $spoofed): IncomingRequest
    {
        $request = new IncomingRequest($config, new URI('http://localhost/'), '', new UserAgent());
        // Simulate the real connection address the web server would pass through; the
        // CLI test SAPI has no REMOTE_ADDR, so set the server global explicitly.
        $request->setGlobal('server', ['REMOTE_ADDR' => '10.9.9.9']);
        $request->setHeader('X-Forwarded-For', $spoofed);
        $request->setHeader('X-Real-IP', $spoofed);

        return $request;
    }

    public function testForwardedForIsIgnoredUnderTheAppsRealConfig(): void
    {
        // Real config: proxyIPs = []. A spoofed X-Forwarded-For is ignored, so
        // getIPAddress() returns the true connection address the throttle keys on.
        $config = new App();
        $this->assertSame([], $config->proxyIPs, 'app must not blanket-trust a forwarding header');

        $request = $this->requestWithForwardedFor($config, '203.0.113.7');
        $this->assertSame('10.9.9.9', $request->getIPAddress());
    }

    public function testAConfigThatTrustsTheHeaderWouldSurfaceTheSpoofedValue(): void
    {
        // Contrast case, proving the assertion above is not vacuous: a config that
        // blanket-trusts X-Forwarded-For DOES surface the forged address. This is the
        // misconfiguration the empty proxyIPs default exists to prevent — never trust
        // a forwarding header from an unbounded range.
        $trustAll           = new App();
        $trustAll->proxyIPs = ['0.0.0.0/0' => 'X-Forwarded-For'];

        $request = $this->requestWithForwardedFor($trustAll, '203.0.113.7');
        $this->assertSame('203.0.113.7', $request->getIPAddress());
    }
}

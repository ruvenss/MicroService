<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class ApiHealthTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        cache()->clean(); // isolate the short-lived readiness cache between tests
    }

    public function testReadinessChecksDatabaseAndCache(): void
    {
        $result = $this->get('api/v1/health');

        $result->assertStatus(200);

        $json = json_decode($result->getJSON() ?: '{}', true);
        $this->assertSame('ok', $json['data']['status']);
        $this->assertSame('microservice', $json['data']['service']);
        $this->assertSame('up', $json['data']['checks']['database']);
        $this->assertSame('up', $json['data']['checks']['cache']);
        // `time` is the same Z-suffixed UTC shape as every stored timestamp on the
        // wire (not an offset like +00:00), so a consumer parses all with one rule.
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $json['data']['time']);
    }

    public function testLivenessProbeIsDependencyFree(): void
    {
        $result = $this->get('api/v1/health?probe=live');

        $result->assertStatus(200);

        $json = json_decode($result->getJSON() ?: '{}', true);
        $this->assertSame('ok', $json['data']['status']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $json['data']['time']);
        // Liveness must not report dependency checks — it only says "process is up".
        $this->assertArrayNotHasKey('checks', $json['data']);
    }

    public function testHealthNeedsNoApiKey(): void
    {
        // Open endpoint (no Authorization) — monitors/orchestrators hit it directly.
        $this->get('api/v1/health')->assertStatus(200);
    }

    public function testReadinessIsServedFromCacheWhenFresh(): void
    {
        // Seed a fresh readiness result marking the DB down. The endpoint must
        // report it straight from cache — without re-probing the (actually up) DB —
        // proving repeated probes don't hit the database each time.
        cache()->save('health_readiness', ['database' => false, 'cache' => true], 5);

        $result = $this->get('api/v1/health');
        $result->assertStatus(503);

        $json = json_decode($result->getJSON() ?: '{}', true);
        $this->assertSame('degraded', $json['data']['status']);
        $this->assertSame('down', $json['data']['checks']['database']);

        // A 503 tells the client when to retry (RFC 7231); the value is the readiness
        // cache TTL (5 s), since the state cannot change before then.
        $this->assertSame('5', $result->response()->getHeaderLine('Retry-After'));
    }

    public function testLivenessIgnoresTheReadinessCache(): void
    {
        // Even a "degraded" cached readiness must not affect liveness (process-up).
        cache()->save('health_readiness', ['database' => false, 'cache' => false], 5);

        $this->get('api/v1/health?probe=live')->assertStatus(200);
    }
}

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

    public function testReadinessChecksDatabaseAndCache(): void
    {
        $result = $this->get('api/v1/health');

        $result->assertStatus(200);

        $json = json_decode($result->getJSON() ?: '{}', true);
        $this->assertSame('ok', $json['data']['status']);
        $this->assertSame('microservice', $json['data']['service']);
        $this->assertSame('up', $json['data']['checks']['database']);
        $this->assertSame('up', $json['data']['checks']['cache']);
        $this->assertArrayHasKey('time', $json['data']);
    }

    public function testLivenessProbeIsDependencyFree(): void
    {
        $result = $this->get('api/v1/health?probe=live');

        $result->assertStatus(200);

        $json = json_decode($result->getJSON() ?: '{}', true);
        $this->assertSame('ok', $json['data']['status']);
        // Liveness must not report dependency checks — it only says "process is up".
        $this->assertArrayNotHasKey('checks', $json['data']);
    }

    public function testHealthNeedsNoApiKey(): void
    {
        // Open endpoint (no Authorization) — monitors/orchestrators hit it directly.
        $this->get('api/v1/health')->assertStatus(200);
    }
}

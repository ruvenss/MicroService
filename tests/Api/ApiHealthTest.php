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

    public function testHealthReturnsOkEnvelope(): void
    {
        $result = $this->get('api/v1/health');

        $result->assertStatus(200);

        $json = json_decode($result->getJSON() ?: '{}', true);
        $this->assertSame('ok', $json['data']['status']);
        $this->assertSame('microservice', $json['data']['service']);
        $this->assertSame('up', $json['data']['checks']['database']);
        $this->assertArrayHasKey('time', $json['data']);
    }
}

<?php

declare(strict_types=1);

use App\Libraries\RequestContext;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class RequestIdTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        RequestContext::reset();
    }

    public function testGeneratesAndEchoesRequestId(): void
    {
        $response = $this->get('api/v1/health')->response();
        $id       = $response->getHeaderLine('X-Request-Id');

        $this->assertNotSame('', $id);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._-]{1,128}$/', $id);
    }

    public function testAdoptsWellFormedInboundId(): void
    {
        $response = $this->withHeaders(['X-Request-Id' => 'trace-abc-123'])
            ->get('api/v1/health')
            ->response();

        $this->assertSame('trace-abc-123', $response->getHeaderLine('X-Request-Id'));
    }

    public function testRejectsMalformedInboundId(): void
    {
        $response = $this->withHeaders(['X-Request-Id' => 'has space and #'])
            ->get('api/v1/health')
            ->response();

        $this->assertNotSame('has space and #', $response->getHeaderLine('X-Request-Id'));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._-]{1,128}$/', $response->getHeaderLine('X-Request-Id'));
    }
}

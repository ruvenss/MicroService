<?php

declare(strict_types=1);

use Tests\Support\FeatureTestCase;

/**
 * @internal
 */
final class RateLimitTest extends FeatureTestCase
{
    public function testExceedingLimitReturns429WithHeaders(): void
    {
        $headers = ['Authorization' => 'Bearer ' . $this->makeKey(['products:read'], 'active', null, 2)];

        $this->withHeaders($headers)->get('api/v1/products')->assertStatus(200);
        $this->withHeaders($headers)->get('api/v1/products')->assertStatus(200);

        $third    = $this->withHeaders($headers)->get('api/v1/products');
        $response = $third->response();

        $third->assertStatus(429);
        $this->assertNotSame('', $response->getHeaderLine('Retry-After'));
        $this->assertSame('2', $response->getHeaderLine('X-RateLimit-Limit'));
        $this->assertSame('0', $response->getHeaderLine('X-RateLimit-Remaining'));
    }

    public function testRateLimitHeadersOnSuccess(): void
    {
        $headers  = ['Authorization' => 'Bearer ' . $this->makeKey(['products:read'], 'active', null, 10)];
        $response = $this->withHeaders($headers)->get('api/v1/products')->response();

        $this->assertSame('10', $response->getHeaderLine('X-RateLimit-Limit'));
        $this->assertSame('9', $response->getHeaderLine('X-RateLimit-Remaining'));
    }
}

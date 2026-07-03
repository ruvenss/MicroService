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
        // Reset tells a client (n8n) exactly when the window frees up.
        $this->assertGreaterThan(time(), (int) $response->getHeaderLine('X-RateLimit-Reset'));
    }

    public function testRateLimitHeadersOnSuccess(): void
    {
        $headers  = ['Authorization' => 'Bearer ' . $this->makeKey(['products:read'], 'active', null, 10)];
        $response = $this->withHeaders($headers)->get('api/v1/products')->response();

        $this->assertSame('10', $response->getHeaderLine('X-RateLimit-Limit'));
        $this->assertSame('9', $response->getHeaderLine('X-RateLimit-Remaining'));
        // Reset is a future epoch second (proactive self-throttling, not just reactive 429s).
        $reset = (int) $response->getHeaderLine('X-RateLimit-Reset');
        $this->assertGreaterThan(time(), $reset);
        $this->assertLessThanOrEqual(time() + 60, $reset);
    }
}

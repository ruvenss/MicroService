<?php

declare(strict_types=1);

use Tests\Support\FeatureTestCase;

/**
 * Brute-force / DoS guard: repeated auth failures from one IP get throttled
 * (429), while valid keys are never affected.
 *
 * @internal
 */
final class AuthThrottleTest extends FeatureTestCase
{
    public function testRepeatedAuthFailuresAreThrottled(): void
    {
        $headers = ['Authorization' => 'Bearer bogus.key'];

        // The first 30 failures are answered with a neutral 401 ...
        for ($i = 0; $i < 30; $i++) {
            $this->withHeaders($headers)->get('api/v1/products')->assertStatus(401);
        }

        // ... then the IP is throttled with 429 + Retry-After.
        $throttled = $this->withHeaders($headers)->get('api/v1/products');
        $throttled->assertStatus(429);
        $this->assertNotSame('', $throttled->response()->getHeaderLine('Retry-After'));
    }

    public function testValidKeyIsNeverThrottled(): void
    {
        $headers = $this->authHeaders(['products:read']);

        // Well past the failure threshold: valid requests never fail auth, so the
        // brute-force guard never trips (they hit only the generous per-key limit).
        for ($i = 0; $i < 35; $i++) {
            $this->withHeaders($headers)->get('api/v1/products')->assertStatus(200);
        }
    }
}

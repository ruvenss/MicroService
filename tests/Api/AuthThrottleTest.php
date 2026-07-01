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

        // Each failure is a neutral 401 until the per-IP-per-minute threshold is
        // crossed, then a 429 with Retry-After. The counter is keyed to the clock
        // minute, so hammer well past the threshold to stay boundary-proof. We
        // capture scalars per response (the feature test reuses one response
        // object, so holding references would all read the final status).
        $firstStatus = null;
        $retryAfter  = null;
        for ($i = 0; $i < 70; $i++) {
            $response = $this->withHeaders($headers)->get('api/v1/products');
            $status   = $response->response()->getStatusCode();
            $firstStatus ??= $status;
            if ($status === 429) {
                $retryAfter = $response->response()->getHeaderLine('Retry-After');
                break;
            }
            $this->assertSame(401, $status); // anything not yet throttled must be a neutral 401
        }

        $this->assertSame(401, $firstStatus, 'The reason must never be revealed before throttling.');
        $this->assertNotNull($retryAfter, 'Expected the IP to be throttled with 429 after repeated auth failures.');
        $this->assertNotSame('', $retryAfter); // 429 carries Retry-After
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

<?php

declare(strict_types=1);

use App\Models\ApiKeyModel;
use App\Models\ApiRequestLogModel;
use Tests\Support\FeatureTestCase;

/**
 * @internal
 */
final class UsageTrackingTest extends FeatureTestCase
{
    public function testRequestIsLogged(): void
    {
        $token = $this->makeKey(['products:read']);
        $this->withHeaders(['Authorization' => "Bearer {$token}"])->get('api/v1/products')->assertStatus(200);

        $rows = (new ApiRequestLogModel())->where('resource', 'products')->where('method', 'GET')->findAll();

        $this->assertNotEmpty($rows);
        $this->assertSame(200, (int) $rows[0]['status']);
        $this->assertSame('read', $rows[0]['action']);
        $this->assertNotNull($rows[0]['api_key_id']);
    }

    public function testLastUsedAtIsStamped(): void
    {
        $token  = $this->makeKey(['products:read']);
        $prefix = explode('.', $token)[0];

        $this->withHeaders(['Authorization' => "Bearer {$token}"])->get('api/v1/products');

        $key = (new ApiKeyModel())->where('prefix', $prefix)->first();
        $this->assertNotNull($key['last_used_at']);
    }

    public function testLastUsedAtIsThrottledWithinTheWindow(): void
    {
        $token   = $this->makeKey(['products:read']);
        $headers = ['Authorization' => "Bearer {$token}"];
        $keyId   = (int) (new ApiKeyModel())->where('prefix', explode('.', $token)[0])->first()['id'];

        // First request stamps last_used_at and arms the throttle marker.
        $this->withHeaders($headers)->get('api/v1/products');

        // Backdate it so a re-stamp would be detectable.
        db_connect()->table('api_keys')->where('id', $keyId)->update(['last_used_at' => '2020-01-01 00:00:00']);

        // A second request within the window must NOT re-stamp it (one write per key
        // per window, not per request — no api_keys row-lock contention under load).
        $this->withHeaders($headers)->get('api/v1/products');
        $this->assertSame(
            '2020-01-01 00:00:00',
            (string) (new ApiKeyModel())->find($keyId)['last_used_at'],
        );

        // Once the throttle marker clears, the next request stamps again.
        cache()->delete('lastused_' . $keyId);
        $this->withHeaders($headers)->get('api/v1/products');
        $this->assertNotSame('2020-01-01 00:00:00', (string) (new ApiKeyModel())->find($keyId)['last_used_at']);
    }
}

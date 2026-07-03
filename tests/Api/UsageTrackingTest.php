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

    public function testLongPathRequestIsStillLoggedTruncatedNotDropped(): void
    {
        // A >255-char URL still reaches PHP (under Apache's request-line limit). `path`
        // is VARCHAR(255); under MySQL's STRICT_TRANS_TABLES an over-length insert is
        // REJECTED, and the fail-open catch would then drop the access-audit row — a hole
        // in "every request is logged", exactly for the long-path probes an exposed
        // service most wants recorded. Force strict mode (production uses it) so the test
        // reflects reality, then assert the row survives (path truncated to fit).
        db_connect()->query("SET SESSION sql_mode = 'STRICT_TRANS_TABLES'");

        $token = $this->makeKey(['products:read']);
        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->get('api/v1/products/' . str_repeat('a', 300)); // ~316-char path → 404, but must log

        $rows = (new ApiRequestLogModel())->like('path', '/api/v1/products/aaaaaaaaaa', 'after')->findAll();

        $this->assertNotEmpty($rows, 'a long-path request must still produce an access-audit row');
        $this->assertLessThanOrEqual(255, mb_strlen((string) $rows[0]['path']), 'path is truncated to the column width');
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

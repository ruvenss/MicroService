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
}

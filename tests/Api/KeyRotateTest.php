<?php

declare(strict_types=1);

use App\Models\ApiKeyModel;
use Tests\Support\FeatureTestCase;

/**
 * API key rotation with a grace window: after rotate, both the old and the new
 * secret authenticate until the grace deadline, then only the new one — so an
 * n8n workflow can migrate credentials without downtime.
 *
 * @internal
 */
final class KeyRotateTest extends FeatureTestCase
{
    /** @return array{0: string, 1: string} [prefix, fullOldKey] */
    private function seedKey(): array
    {
        $old    = $this->makeKey(['products:read']);
        $prefix = explode('.', $old)[0];

        return [$prefix, $old];
    }

    private function statusFor(string $bearer): int
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $bearer])
            ->get('api/v1/products')->response()->getStatusCode();
    }

    public function testBothSecretsWorkDuringGrace(): void
    {
        [$prefix, $old] = $this->seedKey();
        $this->assertSame(200, $this->statusFor($old)); // old works before rotation

        $result = (new ApiKeyModel())->rotate($prefix, 24);
        $this->assertNotNull($result);
        $new = $prefix . '.' . $result['secret'];

        // Grace window open: BOTH authenticate.
        $this->assertSame(200, $this->statusFor($old));
        $this->assertSame(200, $this->statusFor($new));
    }

    public function testOldSecretStopsWorkingAfterGrace(): void
    {
        [$prefix, $old] = $this->seedKey();
        $result         = (new ApiKeyModel())->rotate($prefix, 24);
        $new            = $prefix . '.' . $result['secret'];

        // Fast-forward past the grace window.
        $model = new ApiKeyModel();
        $id    = $model->where('prefix', $prefix)->first()['id'];
        $model->update($id, ['previous_expires_at' => date('Y-m-d H:i:s', time() - 1)]);

        $this->assertSame(401, $this->statusFor($old)); // old now rejected
        $this->assertSame(200, $this->statusFor($new)); // new still fine
    }

    public function testRotateUnknownPrefixReturnsNull(): void
    {
        $this->assertNull((new ApiKeyModel())->rotate('no-such-prefix', 24));
    }

    public function testVerifySecretHonoursCurrentAndGracePrevious(): void
    {
        $model = new ApiKeyModel();
        $key   = [
            'secret_hash'          => hash('sha256', 'current'),
            'secret_hash_previous' => hash('sha256', 'previous'),
            'previous_expires_at'  => date('Y-m-d H:i:s', time() + 3600),
        ];

        $this->assertTrue($model->verifySecret($key, 'current'));
        $this->assertTrue($model->verifySecret($key, 'previous'));
        $this->assertFalse($model->verifySecret($key, 'wrong'));

        // Once the grace window closes, the previous secret is refused.
        $key['previous_expires_at'] = date('Y-m-d H:i:s', time() - 1);
        $this->assertFalse($model->verifySecret($key, 'previous'));
        $this->assertTrue($model->verifySecret($key, 'current'));
    }

    public function testRotateCommandInstallsAGracePrevious(): void
    {
        [$prefix] = $this->seedKey();

        ob_start();
        command('key:rotate ' . $prefix);
        ob_end_clean();

        $row = (new ApiKeyModel())->where('prefix', $prefix)->first();
        $this->assertNotNull($row['secret_hash_previous']);
        $this->assertNotNull($row['previous_expires_at']);
    }
}

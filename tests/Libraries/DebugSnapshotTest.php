<?php

declare(strict_types=1);

use App\Libraries\Debug\DebugSnapshot;
use Tests\Support\FeatureTestCase;

/**
 * @internal
 */
final class DebugSnapshotTest extends FeatureTestCase
{
    public function testFullSnapshotExposesEveryPanel(): void
    {
        $snap = (new DebugSnapshot())->full();

        foreach (['time', 'environment', 'runtime', 'health', 'requests', 'resources', 'routes', 'keys', 'webhooks', 'idempotency'] as $panel) {
            $this->assertArrayHasKey($panel, $snap);
        }

        $this->assertSame(ENVIRONMENT, $snap['environment']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $snap['time']);
        $this->assertTrue(array_is_list($snap['requests']));
        $this->assertTrue(array_is_list($snap['keys']));
    }

    public function testRuntimeAndHealthReportLiveState(): void
    {
        $snap = (new DebugSnapshot())->full();

        $this->assertSame(PHP_VERSION, $snap['runtime']['php_version']);
        $this->assertArrayHasKey('opcache_enabled', $snap['runtime']);
        $this->assertArrayHasKey('jit_enabled', $snap['runtime']);

        // The migrated test DB is reachable, so readiness is up.
        $this->assertSame('up', $snap['health']['database']);
        $this->assertArrayHasKey('cache_handler', $snap['health']);
    }

    public function testKeysPanelExposesMetadataButNeverSecretMaterial(): void
    {
        // Seed a real key; its secret hash must not surface anywhere in the snapshot.
        [$prefix, $secret] = explode('.', $this->makeKey(['products:read'], 'active'));
        $hash              = hash('sha256', $secret);

        $snap = (new DebugSnapshot())->full();
        $this->assertNotEmpty($snap['keys']);

        $row = $snap['keys'][array_key_last($snap['keys'])];
        $this->assertSame($prefix, $row['prefix']);
        $this->assertSame(['products:read'], $row['scopes']);
        $this->assertSame('active', $row['status']);
        $this->assertArrayNotHasKey('secret_hash', $row);
        $this->assertArrayNotHasKey('secret_hash_previous', $row);

        // Belt-and-braces: the serialised snapshot never contains the hash.
        $this->assertStringNotContainsString($hash, (string) json_encode($snap));
    }

    public function testCountPanelsAreShaped(): void
    {
        $snap = (new DebugSnapshot())->full();

        $this->assertIsArray($snap['webhooks']);          // status => count map (possibly empty)
        $this->assertIsInt($snap['idempotency']['total']);
    }

    public function testRequestsLimitIsHonoured(): void
    {
        $snap = (new DebugSnapshot(3))->full();

        $this->assertLessThanOrEqual(3, count($snap['requests']));
    }
}

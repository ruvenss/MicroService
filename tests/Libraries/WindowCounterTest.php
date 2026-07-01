<?php

declare(strict_types=1);

use App\Libraries\WindowCounter;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The shared fixed-window counter behind the rate limiter and the auth-failure
 * throttle. These exercise the file-cache fallback path (tests run without Redis);
 * the atomic Redis path is verified end-to-end in the container.
 *
 * @internal
 */
final class WindowCounterTest extends CIUnitTestCase
{
    private function bucket(): string
    {
        return 'wctest_' . bin2hex(random_bytes(4));
    }

    public function testCountsUpFromOne(): void
    {
        $cache  = service('cache');
        $bucket = $this->bucket();

        $this->assertSame(1, WindowCounter::hit($cache, $bucket, 60));
        $this->assertSame(2, WindowCounter::hit($cache, $bucket, 60));
        $this->assertSame(3, WindowCounter::hit($cache, $bucket, 60));

        $cache->delete($bucket);
    }

    public function testBucketsAreIndependent(): void
    {
        $cache = service('cache');
        $a     = $this->bucket();
        $b     = $this->bucket();

        WindowCounter::hit($cache, $a, 60);
        WindowCounter::hit($cache, $a, 60);

        // A different bucket starts its own count — one key's traffic never bleeds
        // into another's limit.
        $this->assertSame(1, WindowCounter::hit($cache, $b, 60));
        $this->assertSame(3, WindowCounter::hit($cache, $a, 60));

        $cache->delete($a);
        $cache->delete($b);
    }
}

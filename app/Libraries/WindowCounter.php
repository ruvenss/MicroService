<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Libraries\Cache\AtomicPredisHandler;
use CodeIgniter\Cache\CacheInterface;
use Throwable;

/**
 * A fixed-window hit counter shared by the abuse guards (per-key rate limiting and
 * per-IP auth-failure throttling). Both need the same thing: "increment this
 * window's counter and tell me the new total" — and both must hold under a
 * concurrent burst, since that is exactly the abuse they defend against.
 *
 * On Redis the increment is one atomic Lua step (INCRBY + first-hit EXPIRE) via
 * AtomicPredisHandler, so simultaneous requests can't race past the threshold. On
 * the file backend (dev/tests) it degrades to a read-modify-write — adequate at
 * single-process concurrency and observably identical. Centralising it here keeps
 * that correctness in one tested place instead of copy-pasted in each filter.
 */
final class WindowCounter
{
    /**
     * Increment the counter under $bucket (creating it with a $ttlSeconds expiry)
     * and return the new count.
     */
    public static function hit(CacheInterface $cache, string $bucket, int $ttlSeconds): int
    {
        if ($cache instanceof AtomicPredisHandler) {
            try {
                return $cache->incrementWindow($bucket, $ttlSeconds);
            } catch (Throwable) {
                // A legacy hash-typed bucket left over across a deploy (or any Redis
                // hiccup) makes the atomic call throw; fall back rather than 500.
            }
        }

        $count = (int) $cache->get($bucket) + 1;
        $cache->save($bucket, $count, $ttlSeconds);

        return $count;
    }
}

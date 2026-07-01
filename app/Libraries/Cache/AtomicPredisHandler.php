<?php

declare(strict_types=1);

namespace App\Libraries\Cache;

use CodeIgniter\Cache\Handlers\PredisHandler;

/**
 * PredisHandler with a genuinely atomic fixed-window counter.
 *
 * The framework's `increment()` is unusable for counters on Predis: it does
 * `HINCRBY <key> data <n>`, but `save()`/`get()` store the value under the
 * `__ci_value` hash field — so the incremented number is invisible to `get()`
 * and carries no TTL. The rate limiter therefore fell back to a
 * read-modify-write (`get()` → `+1` → `save()`), which races under concurrency:
 * N simultaneous requests all read the same count and overwrite it, so a burst
 * (an exposed service, or an n8n fan-out) slips past the per-key limit.
 *
 * `incrementWindow()` does the whole thing in one server-side Lua step —
 * `INCRBY` plus a first-hit `EXPIRE` — so the count is exact no matter how many
 * requests arrive at once, reusing the cache service's already-persistent
 * connection (no extra socket per request). Only used when Redis is the active
 * backend; the file backend (dev/tests) keeps the read-modify-write, which is
 * fine at its single-process concurrency.
 */
class AtomicPredisHandler extends PredisHandler
{
    /**
     * Atomically increment a fixed-window counter, setting the window TTL when the
     * key is first created, and return the new count.
     *
     * The counter is a plain Redis integer (INCRBY), independent of the __ci_value
     * hash shape used by get()/save() — the caller reads the count from the return
     * value, never via get(), so the two never interfere.
     */
    public function incrementWindow(string $key, int $ttlSeconds, int $offset = 1): int
    {
        $key = static::validateKey($key);

        // KEYS[1] = bucket, ARGV[1] = offset, ARGV[2] = ttl. EXPIRE only on the first
        // hit (when INCRBY returns exactly the offset), so later hits in the window
        // don't keep pushing the expiry out.
        $lua = <<<'LUA'
            local count = redis.call('INCRBY', KEYS[1], ARGV[1])
            if count == tonumber(ARGV[1]) then
                redis.call('EXPIRE', KEYS[1], ARGV[2])
            end
            return count
            LUA;

        // Redis passes ARGV as strings; the Lua tonumber()/INCRBY handle the coercion.
        return (int) $this->redis->eval($lua, 1, $key, (string) $offset, (string) $ttlSeconds);
    }
}

<?php

declare(strict_types=1);

namespace App\Filters;

use App\Libraries\ApiProblem;
use App\Libraries\AuthContext;
use App\Libraries\Cache\AtomicPredisHandler;
use App\Libraries\RateLimitState;
use CodeIgniter\Cache\CacheInterface;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Per-key fixed-window rate limiter.
 *
 * Counters live in the cache service (file in dev/test, Redis in production —
 * see Config\Cache), so the limiter is Redis-backed without a hard dependency
 * on the extension in tests. Exceeding the limit returns 429 + Retry-After; the
 * full trio X-RateLimit-Limit / -Remaining / -Reset (epoch second the window
 * frees up) is surfaced on every response so a client (n8n) can self-throttle
 * proactively instead of only reacting to a 429.
 */
class RateLimit implements FilterInterface
{
    private const DEFAULT_LIMIT = 120; // requests per window
    private const WINDOW        = 60;  // seconds

    public function before(RequestInterface $request, $arguments = null)
    {
        $keyId = AuthContext::keyId();
        if ($keyId === null) {
            return null; // unauthenticated requests are stopped earlier by ApiKeyAuth
        }

        $limit = AuthContext::rateLimit() ?? self::DEFAULT_LIMIT;
        if ($limit <= 0) {
            return null; // 0 disables limiting for this key
        }

        $window  = (int) floor(time() / self::WINDOW);
        $bucket  = "ratelimit_{$keyId}_{$window}"; // ':' is reserved in CI cache keys
        $resetAt = ($window + 1) * self::WINDOW;

        // Count this request atomically (see hit()). A count > limit means the window
        // is already spent, so this one is rejected.
        $count     = $this->hit(service('cache'), $bucket);
        $remaining = max(0, $limit - $count);
        RateLimitState::set($limit, $remaining, $resetAt);

        if ($count > $limit) {
            return ApiProblem::respond(429, 'Rate limit exceeded.')
                ->setHeader('Retry-After', (string) max(1, $resetAt - time()))
                ->setHeader('X-RateLimit-Limit', (string) $limit)
                ->setHeader('X-RateLimit-Remaining', '0')
                ->setHeader('X-RateLimit-Reset', (string) $resetAt);
        }

        return null;
    }

    /**
     * Increment the window counter and return the new count.
     *
     * On Redis the whole thing is one atomic Lua step, so a concurrent burst cannot
     * race past the limit. On the file backend (dev/tests) it degrades to a
     * read-modify-write — adequate at single-process concurrency, and identical in
     * observable behaviour. A legacy hash-typed bucket left over across a deploy (or
     * any Redis hiccup) makes the atomic call throw; we fall back rather than 500.
     */
    private function hit(CacheInterface $cache, string $bucket): int
    {
        if ($cache instanceof AtomicPredisHandler) {
            try {
                return $cache->incrementWindow($bucket, self::WINDOW);
            } catch (Throwable) {
                // fall through to the read-modify-write path
            }
        }

        $count = (int) $cache->get($bucket) + 1;
        $cache->save($bucket, $count, self::WINDOW);

        return $count;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        if (RateLimitState::isSet()) {
            $response->setHeader('X-RateLimit-Limit', (string) RateLimitState::limit());
            $response->setHeader('X-RateLimit-Remaining', (string) RateLimitState::remaining());
            $response->setHeader('X-RateLimit-Reset', (string) RateLimitState::resetAt());
        }

        return $response;
    }
}

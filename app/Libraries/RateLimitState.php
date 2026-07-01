<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Carries the rate-limit outcome from the before-filter to the after-filter so
 * X-RateLimit-* headers can be added to the response.
 */
final class RateLimitState
{
    private static bool $set = false;
    private static int $limit = 0;
    private static int $remaining = 0;
    private static int $resetAt = 0;

    /**
     * @param int $resetAt epoch second at which the current window resets
     */
    public static function set(int $limit, int $remaining, int $resetAt = 0): void
    {
        self::$set       = true;
        self::$limit     = $limit;
        self::$remaining = $remaining;
        self::$resetAt   = $resetAt;
    }

    public static function isSet(): bool
    {
        return self::$set;
    }

    public static function limit(): int
    {
        return self::$limit;
    }

    public static function remaining(): int
    {
        return self::$remaining;
    }

    public static function resetAt(): int
    {
        return self::$resetAt;
    }

    public static function reset(): void
    {
        self::$set       = false;
        self::$limit     = 0;
        self::$remaining = 0;
        self::$resetAt   = 0;
    }
}

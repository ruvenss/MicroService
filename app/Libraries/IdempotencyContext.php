<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Carries a pending Idempotency-Key from the before-filter to the after-filter
 * so a first-time write's response can be recorded for future replays.
 */
final class IdempotencyContext
{
    private static ?string $key = null;
    private static string $hash = '';
    private static bool $pending = false;

    public static function arm(string $key, string $hash): void
    {
        self::$key     = $key;
        self::$hash    = $hash;
        self::$pending = true;
    }

    public static function isPending(): bool
    {
        return self::$pending;
    }

    public static function key(): ?string
    {
        return self::$key;
    }

    public static function hash(): string
    {
        return self::$hash;
    }

    public static function reset(): void
    {
        self::$key     = null;
        self::$hash    = '';
        self::$pending = false;
    }
}

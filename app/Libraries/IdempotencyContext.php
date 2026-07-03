<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Carries a claimed Idempotency-Key row from the before-filter to the after-filter.
 * The before-filter atomically *claims* the key (inserts a pending row) and records
 * its id here; the after-filter finalises that same row — writing the response on
 * success or releasing the claim on failure.
 */
final class IdempotencyContext
{
    private static ?string $key = null;
    private static string $hash = '';
    private static bool $pending = false;
    private static ?int $id = null;

    public static function arm(string $key, string $hash, int $id): void
    {
        self::$key     = $key;
        self::$hash    = $hash;
        self::$id      = $id;
        self::$pending = true;
    }

    /** Primary key of the pending claim row this request owns. */
    public static function id(): ?int
    {
        return self::$id;
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
        self::$id      = null;
        self::$pending = false;
    }
}

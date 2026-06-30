<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Holds the authenticated API key for the current request, shared between the
 * auth filter, the permission filter, and (later) usage tracking.
 */
final class AuthContext
{
    private static ?int $keyId = null;

    /** @var list<string> */
    private static array $scopes = [];

    /**
     * @param list<string> $scopes
     */
    public static function set(int $keyId, array $scopes): void
    {
        self::$keyId  = $keyId;
        self::$scopes = $scopes;
    }

    public static function keyId(): ?int
    {
        return self::$keyId;
    }

    /**
     * @return list<string>
     */
    public static function scopes(): array
    {
        return self::$scopes;
    }

    public static function isAuthenticated(): bool
    {
        return self::$keyId !== null;
    }

    public static function reset(): void
    {
        self::$keyId  = null;
        self::$scopes = [];
    }
}

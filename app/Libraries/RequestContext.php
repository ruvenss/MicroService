<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Per-request correlation id, shared across filters, controllers, and the
 * exception handler. Echoed as `X-Request-Id` and embedded in problem+json so
 * an n8n workflow (or a log search) can trace a single call end to end.
 */
final class RequestContext
{
    private static string $requestId = '';

    /** Accept only sane inbound ids; otherwise we mint our own. */
    private const ID_PATTERN = '/^[A-Za-z0-9._-]{1,128}$/';

    public static function id(): string
    {
        if (self::$requestId === '') {
            self::$requestId = bin2hex(random_bytes(12));
        }

        return self::$requestId;
    }

    /**
     * Adopt a client-supplied id only if it is well-formed (prevents header
     * injection / log forging); otherwise keep/generate our own.
     */
    public static function adopt(?string $candidate): string
    {
        if ($candidate !== null && preg_match(self::ID_PATTERN, $candidate) === 1) {
            self::$requestId = $candidate;
        }

        return self::id();
    }

    /** Reset between tests so ids don't bleed across cases. */
    public static function reset(): void
    {
        self::$requestId = '';
    }
}

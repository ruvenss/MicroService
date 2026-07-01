<?php

declare(strict_types=1);

namespace App\Libraries;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Formats stored (UTC) DATETIME strings as unambiguous ISO-8601 with a `Z`, e.g.
 * `2026-07-01 10:19:30` → `2026-07-01T10:19:30Z`, so n8n's date handling never has
 * to guess the zone. Used by the generic resource cast and the meta endpoints
 * (_audit / _archive / _me) alike, so every timestamp on the wire is consistent.
 */
final class Timestamp
{
    /** null / empty / unparseable values pass through unchanged. */
    public static function iso(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        } catch (Exception) {
            return $value;
        }
    }
}

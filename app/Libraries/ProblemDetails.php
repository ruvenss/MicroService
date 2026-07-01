<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Builds RFC 9457 "Problem Details" error bodies (application/problem+json).
 *
 * Deliberately neutral: never includes exception classes, file paths, or stack
 * traces, so error responses disclose nothing about the engine behind the API.
 */
final class ProblemDetails
{
    /**
     * Human-readable titles per HTTP status. Kept generic on purpose.
     *
     * @var array<int, string>
     */
    private const TITLES = [
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        409 => 'Conflict',
        412 => 'Precondition Failed',
        413 => 'Content Too Large',
        415 => 'Unsupported Media Type',
        422 => 'Unprocessable Entity',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        503 => 'Service Unavailable',
    ];

    /**
     * @param array<string, mixed> $extra Additional members (e.g. 'errors', 'requestId').
     *
     * @return array<string, mixed>
     */
    public static function make(int $status, ?string $detail = null, array $extra = []): array
    {
        $problem = [
            'type'   => 'about:blank',
            'title'  => self::TITLES[$status] ?? 'Error',
            'status' => $status,
        ];

        if ($detail !== null && $detail !== '') {
            $problem['detail'] = $detail;
        }

        return [...$problem, ...$extra];
    }

    public static function titleFor(int $status): string
    {
        return self::TITLES[$status] ?? 'Error';
    }
}

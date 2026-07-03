<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Filters\Stealth;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Builds a ready-to-return, engine-hardened problem+json response. Used by
 * filters (which run outside the controller) to short-circuit with the same
 * neutral error shape used everywhere else.
 */
final class ApiProblem
{
    /**
     * @param array<string, mixed> $extra
     */
    public static function respond(int $status, ?string $detail = null, array $extra = []): ResponseInterface
    {
        $response = service('response');
        Stealth::harden($response);

        $body = ProblemDetails::make($status, $detail, ['requestId' => RequestContext::id()] + $extra);

        return $response
            ->setStatusCode($status)
            ->setBody((string) json_encode($body))
            ->setContentType('application/problem+json');
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Libraries\ProblemDetails;
use App\Libraries\RequestContext;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Neutral error responses.
 *
 * Used as the framework's 404 override so that unknown paths (and the bare
 * root) return a minimal RFC 9457 problem+json body that discloses nothing
 * about the engine, the router, or the application structure.
 */
class Errors extends BaseController
{
    public function notFound(): ResponseInterface
    {
        $body = ProblemDetails::make(404, null, [
            'requestId' => RequestContext::id(),
        ]);

        return $this->response
            ->setStatusCode(404)
            ->setBody((string) json_encode($body))
            ->setContentType('application/problem+json');
    }
}

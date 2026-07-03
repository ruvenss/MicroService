<?php

declare(strict_types=1);

namespace App\Filters;

use App\Libraries\ApiProblem;
use App\Libraries\Authorization;
use App\Libraries\AuthContext;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Enforces that the authenticated key holds the scope required for the request:
 * `{resource}:{action}` where resource is the first path segment after /api/v1
 * and action is derived from the HTTP method. Runs after ApiKeyAuth.
 */
class RequirePermission implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $segments = $request->getUri()->getSegments(); // ['api', 'v1', '{resource}', ...]
        $resource = $segments[2] ?? '';

        if ($resource === '') {
            return ApiProblem::respond(403, 'Forbidden.');
        }

        $required = Authorization::requiredScope($resource, $request->getMethod());

        if (! Authorization::satisfies(AuthContext::scopes(), $required)) {
            return ApiProblem::respond(403, 'The API key lacks the required scope for this operation.');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}

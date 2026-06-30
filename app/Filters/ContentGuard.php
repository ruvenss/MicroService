<?php

declare(strict_types=1);

namespace App\Filters;

use App\Libraries\ProblemDetails;
use App\Libraries\RequestContext;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Guards write requests: a body-carrying POST/PUT/PATCH must declare a JSON
 * content type and contain well-formed JSON. Failures short-circuit with a
 * neutral problem+json (415 or 400) before any controller runs.
 */
class ContentGuard implements FilterInterface
{
    /** @var list<string> */
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH'];

    public function before(RequestInterface $request, $arguments = null)
    {
        if (! in_array(strtoupper($request->getMethod()), self::WRITE_METHODS, true)) {
            return null;
        }

        $body = (string) $request->getBody();
        if ($body === '') {
            return null; // nothing to validate
        }

        if (! $this->isJsonType($request->getHeaderLine('Content-Type'))) {
            return $this->problem(415, 'Content-Type must be application/json.');
        }

        json_decode($body);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->problem(400, 'Request body is not valid JSON.');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }

    private function isJsonType(string $contentType): bool
    {
        $contentType = strtolower($contentType);

        return str_contains($contentType, 'application/json')
            || preg_match('#application/[a-z0-9.+-]*\+json#', $contentType) === 1;
    }

    private function problem(int $status, string $detail): ResponseInterface
    {
        $response = service('response');
        Stealth::harden($response);

        return $response
            ->setStatusCode($status)
            ->setBody((string) json_encode(ProblemDetails::make($status, $detail, [
                'requestId' => RequestContext::id(),
            ])))
            ->setContentType('application/problem+json');
    }
}

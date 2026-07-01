<?php

declare(strict_types=1);

namespace App\Filters;

use App\Libraries\ApiProblem;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Guards write requests: a body-carrying POST/PUT/PATCH must stay within the size
 * limit, declare a JSON content type, and contain well-formed JSON. Failures
 * short-circuit with a neutral problem+json (413/415/400) before any controller
 * runs. The size cap is defence-in-depth behind Apache's LimitRequestBody (which
 * rejects abusive bodies at the edge) — this layer returns a clean, engine-neutral
 * 413 for requests that clear the edge but exceed the API contract.
 */
class ContentGuard implements FilterInterface
{
    /** @var list<string> */
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH'];

    /** Maximum accepted request body, in bytes (1 MiB — comfortably fits a 100-item bulk batch). */
    private const MAX_BODY_BYTES = 1048576;

    public function before(RequestInterface $request, $arguments = null)
    {
        if (! in_array(strtoupper($request->getMethod()), self::WRITE_METHODS, true)) {
            return null;
        }

        // Reject oversized payloads up front — prefer the declared Content-Length
        // (no need to touch the body), with the actual byte length as a backstop
        // for chunked/unlabelled requests.
        if ((int) $request->getHeaderLine('Content-Length') > self::MAX_BODY_BYTES) {
            return $this->problem(413, 'Request body exceeds the maximum allowed size.');
        }

        $body = (string) $request->getBody();
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return $this->problem(413, 'Request body exceeds the maximum allowed size.');
        }

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
        return ApiProblem::respond($status, $detail);
    }
}

<?php

declare(strict_types=1);

namespace App\Filters;

use App\Libraries\ApiProblem;
use App\Libraries\AuthContext;
use App\Models\ApiKeyModel;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Authenticates a bearer API key: `Authorization: Bearer <prefix>.<secret>`.
 *
 * The prefix locates the row; the secret is compared in constant time against
 * the stored SHA-256 hash. Any failure returns a single neutral 401 — the
 * reason (missing, malformed, unknown, revoked, expired, wrong secret) is never
 * disclosed, so keys cannot be probed.
 */
class ApiKeyAuth implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (! preg_match('/^Bearer\s+(\S+)$/i', $request->getHeaderLine('Authorization'), $matches)) {
            return $this->unauthorized();
        }

        $token = $matches[1];
        if (! str_contains($token, '.')) {
            return $this->unauthorized();
        }

        [$prefix, $secret] = explode('.', $token, 2);
        if ($prefix === '' || $secret === '') {
            return $this->unauthorized();
        }

        $model = new ApiKeyModel();
        $key   = $model->findActiveByPrefix($prefix);
        if ($key === null || ! hash_equals((string) $key['secret_hash'], hash('sha256', $secret))) {
            return $this->unauthorized();
        }

        AuthContext::set(
            (int) $key['id'],
            $model->scopesFor((int) $key['id']),
            isset($key['rate_limit']) ? (int) $key['rate_limit'] : null,
        );

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }

    private function unauthorized(): ResponseInterface
    {
        return ApiProblem::respond(401, 'Missing or invalid API key.')
            ->setHeader('WWW-Authenticate', 'Bearer');
    }
}

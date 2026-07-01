<?php

declare(strict_types=1);

namespace App\Filters;

use App\Libraries\ApiProblem;
use App\Libraries\AuthContext;
use App\Models\ApiKeyModel;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Authenticates a bearer API key: `Authorization: Bearer <prefix>.<secret>`.
 *
 * The prefix locates the row; the secret is compared in constant time against
 * the stored SHA-256 hash. Any failure returns a single neutral 401 — the
 * reason (missing, malformed, unknown, revoked, expired, wrong secret) is never
 * disclosed, so keys cannot be probed.
 *
 * Brute-force / DoS guard: repeated auth failures from one IP are counted and,
 * past a per-minute threshold, answered with 429 instead of 401. A valid key
 * never fails, so legitimate (e.g. n8n) traffic is never throttled here.
 */
class ApiKeyAuth implements FilterInterface
{
    /** Failed auth attempts allowed per IP per minute before 429. */
    private const MAX_AUTH_FAILURES = 30;

    public function before(RequestInterface $request, $arguments = null)
    {
        if (! preg_match('/^Bearer\s+(\S+)$/i', $request->getHeaderLine('Authorization'), $matches)) {
            return $this->fail($request);
        }

        $token = $matches[1];
        if (! str_contains($token, '.')) {
            return $this->fail($request);
        }

        [$prefix, $secret] = explode('.', $token, 2);
        if ($prefix === '' || $secret === '') {
            return $this->fail($request);
        }

        $model = new ApiKeyModel();
        $key   = $model->findActiveByPrefix($prefix);
        if ($key === null || ! $model->verifySecret($key, $secret)) {
            return $this->fail($request);
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

    private function fail(RequestInterface $request): ResponseInterface
    {
        $ip     = $request instanceof IncomingRequest ? $request->getIPAddress() : 'unknown';
        $window = (int) floor(time() / 60);
        $bucket = 'authfail_' . md5($ip) . '_' . $window; // md5 keeps IPv6 colons out of the cache key
        $cache  = service('cache');

        $count = (int) $cache->get($bucket) + 1;
        $cache->save($bucket, $count, 60);

        if ($count > self::MAX_AUTH_FAILURES) {
            return ApiProblem::respond(429, 'Too many authentication failures. Try again later.')
                ->setHeader('Retry-After', (string) max(1, 60 - (time() % 60)));
        }

        return ApiProblem::respond(401, 'Missing or invalid API key.')
            ->setHeader('WWW-Authenticate', 'Bearer');
    }
}

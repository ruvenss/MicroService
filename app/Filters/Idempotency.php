<?php

declare(strict_types=1);

namespace App\Filters;

use App\Libraries\ApiProblem;
use App\Libraries\AuthContext;
use App\Libraries\IdempotencyContext;
use App\Models\IdempotencyKeyModel;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Optional idempotency for unsafe writes. A client (e.g. an n8n HTTP node that
 * retries on timeout) sends `Idempotency-Key: <key>`; the first response is
 * recorded and any retry with the same key + request replays it instead of
 * re-executing — no duplicate creates/deletes.
 *
 * Reusing a key with a *different* request → 422. Scoped per authenticated key.
 */
class Idempotency implements FilterInterface
{
    /** @var list<string> */
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    private const TTL = 86400; // 24h

    public function before(RequestInterface $request, $arguments = null)
    {
        $key = trim($request->getHeaderLine('Idempotency-Key'));
        if ($key === '' || ! in_array(strtoupper($request->getMethod()), self::WRITE_METHODS, true)) {
            return null;
        }

        if (preg_match('/^[A-Za-z0-9._:-]{1,255}$/', $key) !== 1) {
            return ApiProblem::respond(400, 'Invalid Idempotency-Key.');
        }

        $hash     = $this->fingerprint($request);
        $existing = (new IdempotencyKeyModel())->findValid(AuthContext::keyId(), $key);

        if ($existing !== null) {
            if ($existing['request_hash'] !== $hash) {
                return ApiProblem::respond(422, 'This Idempotency-Key was already used with a different request.');
            }

            return service('response')
                ->setStatusCode((int) $existing['response_status'])
                ->setBody((string) ($existing['response_body'] ?? ''))
                ->setContentType((string) ($existing['response_type'] ?: 'application/json'))
                ->setHeader('Idempotency-Replayed', 'true');
        }

        IdempotencyContext::arm($key, $hash);

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        if (! IdempotencyContext::isPending()) {
            return $response;
        }

        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            try {
                $now = date('Y-m-d H:i:s');
                (new IdempotencyKeyModel())->insert([
                    'api_key_id'      => AuthContext::keyId(),
                    'idem_key'        => IdempotencyContext::key(),
                    'method'          => strtoupper($request->getMethod()),
                    'path'            => $request->getUri()->getPath(),
                    'request_hash'    => IdempotencyContext::hash(),
                    'response_status' => $status,
                    'response_type'   => $response->getHeaderLine('Content-Type') ?: 'application/json',
                    'response_body'   => (string) $response->getBody(),
                    'created_at'      => $now,
                    'expires_at'      => date('Y-m-d H:i:s', time() + self::TTL),
                ]);
            } catch (Throwable) {
                // Best effort — never break the response over idempotency bookkeeping.
            }
        }

        IdempotencyContext::reset();

        return $response;
    }

    private function fingerprint(RequestInterface $request): string
    {
        return hash('sha256', strtoupper($request->getMethod()) . "\n" . $request->getUri()->getPath() . "\n" . (string) $request->getBody());
    }
}

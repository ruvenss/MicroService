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
 * Concurrency-safe: the key is *claimed* atomically (a pending row) before the
 * write runs, so if a retry arrives while the original is still in flight — n8n's
 * timeout-then-retry overlap — the second request gets `409 in progress` instead
 * of executing the write a second time. The DB unique index on (api key, key) is
 * the atomic primitive; exactly one of N concurrent claims wins.
 *
 * Reusing a key with a *different* request → 422. Scoped per authenticated key.
 */
class Idempotency implements FilterInterface
{
    /** @var list<string> */
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    private const TTL = 86400; // 24h — how long a recorded response stays replayable

    /**
     * A pending claim older than this is treated as abandoned (the original request
     * died before finalising it) and may be taken over. Comfortably above the FPM
     * `request_terminate_timeout` (30 s), so a live in-flight request is never mistaken
     * for a dead one.
     */
    private const STALE_SECONDS = 60;

    public function before(RequestInterface $request, $arguments = null)
    {
        $key = trim($request->getHeaderLine('Idempotency-Key'));
        if ($key === '' || ! in_array(strtoupper($request->getMethod()), self::WRITE_METHODS, true)) {
            return null;
        }

        if (preg_match('/^[A-Za-z0-9._:-]{1,255}$/', $key) !== 1) {
            return ApiProblem::respond(400, 'Invalid Idempotency-Key.');
        }

        $hash  = $this->fingerprint($request);
        $keyId = AuthContext::keyId();
        $model = new IdempotencyKeyModel();
        $row   = $model->findForClaim($keyId, $key);

        if ($row !== null) {
            $expired = strtotime((string) $row['expires_at']) < time();

            if (! $expired) {
                $pending = (int) $row['response_status'] === IdempotencyKeyModel::PENDING_STATUS;
                $differs = $row['request_hash'] !== $hash;

                if (! $pending) {
                    // A completed, still-valid response exists: replay it (or reject a
                    // reuse of the key with a genuinely different request).
                    return $differs
                        ? ApiProblem::respond(422, 'This Idempotency-Key was already used with a different request.')
                        : $this->replay($row);
                }

                // Pending — another request holds the key. Unless the claim is stale
                // (its owner died), refuse to run the write a second time.
                $stale = strtotime((string) $row['created_at']) < time() - self::STALE_SECONDS;
                if (! $stale) {
                    return $differs
                        ? ApiProblem::respond(422, 'This Idempotency-Key was already used with a different request.')
                        : ApiProblem::respond(409, 'A request with this Idempotency-Key is already in progress.')
                            ->setHeader('Retry-After', '2');
                }
                // Stale pending: the original died — fall through and take the key over.
            }

            // Expired, or a dead pending claim: drop the row, then re-claim below.
            $model->release((int) $row['id']);
        }

        $id = $model->claim($keyId, $key, strtoupper($request->getMethod()), $request->getUri()->getPath(), $hash, self::TTL);
        if ($id === null) {
            // Lost the atomic claim race to a concurrent request.
            return ApiProblem::respond(409, 'A request with this Idempotency-Key is already in progress.')
                ->setHeader('Retry-After', '2');
        }

        IdempotencyContext::arm($key, $hash, $id);

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        if (! IdempotencyContext::isPending()) {
            return $response;
        }

        $id     = (int) IdempotencyContext::id();
        $status = $response->getStatusCode();

        try {
            if ($status >= 200 && $status < 300) {
                (new IdempotencyKeyModel())->complete(
                    $id,
                    $status,
                    $response->getHeaderLine('Content-Type') ?: 'application/json',
                    (string) $response->getBody(),
                );
            } else {
                // Non-2xx: drop the claim so the operation can be retried.
                (new IdempotencyKeyModel())->release($id);
            }
        } catch (Throwable) {
            // Best effort — never break the response over idempotency bookkeeping.
        }

        IdempotencyContext::reset();

        return $response;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function replay(array $row): ResponseInterface
    {
        return service('response')
            ->setStatusCode((int) $row['response_status'])
            ->setBody((string) ($row['response_body'] ?? ''))
            ->setContentType((string) ($row['response_type'] ?: 'application/json'))
            ->setHeader('Idempotency-Replayed', 'true');
    }

    private function fingerprint(RequestInterface $request): string
    {
        return hash('sha256', strtoupper($request->getMethod()) . "\n" . $request->getUri()->getPath() . "\n" . (string) $request->getBody());
    }
}

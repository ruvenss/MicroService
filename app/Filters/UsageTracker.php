<?php

declare(strict_types=1);

namespace App\Filters;

use App\Libraries\Authorization;
use App\Libraries\AuthContext;
use App\Libraries\RequestContext;
use App\Models\ApiKeyModel;
use App\Models\ApiRequestLogModel;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Records one append-only row per request (who/what/status/latency) and stamps
 * the key's last_used_at. Runs as an after-filter so it sees the final status —
 * including short-circuited 401/403/415/429 responses. Never throws into the
 * response: logging failures are swallowed.
 */
class UsageTracker implements FilterInterface
{
    /**
     * Stamp a key's `last_used_at` at most once per this many seconds. The access
     * log already records every request precisely; `last_used_at` is a coarse
     * "is this key active" signal (`_me`, stale-key audits), so second precision is
     * pointless — and an unthrottled UPDATE of the *same* api_keys row on every
     * request is write amplification + row-lock contention for a busy (e.g. n8n) key.
     */
    private const LAST_USED_THROTTLE = 60;

    public function before(RequestInterface $request, $arguments = null)
    {
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        try {
            $segments = $request->getUri()->getSegments(); // ['api', 'v1', '{resource}', ...]
            $method   = strtoupper($request->getMethod());
            $start    = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
            $keyId    = AuthContext::keyId();
            $now      = date('Y-m-d H:i:s');

            (new ApiRequestLogModel())->insert([
                'api_key_id' => $keyId,
                'request_id' => RequestContext::id(),
                'method'     => $method,
                'path'       => '/' . ltrim($request->getUri()->getPath(), '/'),
                'resource'   => $segments[2] ?? null,
                'action'     => Authorization::actionForMethod($method),
                'status'     => $response->getStatusCode(),
                'latency_ms' => (int) max(0, round((microtime(true) - $start) * 1000)),
                'ip'         => $request instanceof IncomingRequest ? $request->getIPAddress() : null,
                'created_at' => $now,
            ]);

            if ($keyId !== null && $this->shouldStampLastUsed($keyId)) {
                (new ApiKeyModel())->update($keyId, ['last_used_at' => $now]);
            }
        } catch (Throwable) {
            // Observability must never break the API response.
        }

        return $response;
    }

    /**
     * True at most once per {@see LAST_USED_THROTTLE} seconds per key, via a short
     * cache marker (Redis in prod, file otherwise). So a burst of requests from one
     * key stamps `last_used_at` once, not once per request. If the cache is down the
     * marker never sticks, so it safely falls back to stamping every request.
     */
    private function shouldStampLastUsed(int $keyId): bool
    {
        $cache  = service('cache');
        $marker = 'lastused_' . $keyId;

        if ($cache->get($marker) !== null) {
            return false;
        }

        $cache->save($marker, 1, self::LAST_USED_THROTTLE);

        return true;
    }
}

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

            if ($keyId !== null) {
                (new ApiKeyModel())->update($keyId, ['last_used_at' => $now]);
            }
        } catch (Throwable) {
            // Observability must never break the API response.
        }

        return $response;
    }
}

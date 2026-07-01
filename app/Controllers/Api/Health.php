<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Libraries\ResponseEnvelope;
use App\Libraries\Timestamp;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use Throwable;

/**
 * Liveness/readiness probe.
 *
 * Returns a predictable JSON envelope ({data:{...}}) suitable for uptime monitors,
 * container orchestrators, and n8n HTTP-request nodes.
 *
 *   - `?probe=live` — **liveness**: is the process up and serving? No external
 *     dependency is touched, so an orchestrator never restarts the container just
 *     because the DB or cache blipped (that is a readiness concern). Always 200.
 *   - default / `?probe=ready` — **readiness**: can we actually serve traffic?
 *     Pings the external database and the cache backend (Redis in prod); returns
 *     200 when all are up, else 503 with per-check status.
 */
class Health extends BaseController
{
    /**
     * Readiness result cached this long (seconds). Since the endpoint is open and
     * unauthenticated, a burst of probes (or a flood) would otherwise run a DB
     * `SELECT 1` + cache round-trip per request; caching bounds that to one real
     * probe per window — cheap for monitors, and a guard against health-flood DoS
     * on the external DB if the service is exposed. Short enough that a genuine
     * outage still surfaces well within a Docker healthcheck's retry budget.
     */
    private const READINESS_TTL = 5;

    public function index(): ResponseInterface
    {
        if ($this->request->getGet('probe') === 'live') {
            return $this->response->setStatusCode(200)->setJSON(ResponseEnvelope::wrap([
                'status'  => 'ok',
                'service' => 'microservice',
                'time'    => Timestamp::now(),
            ]));
        }

        [$database, $cache] = $this->readiness();
        $healthy            = $database && $cache;

        return $this->response
            ->setStatusCode($healthy ? 200 : 503)
            ->setJSON(ResponseEnvelope::wrap([
                'status'  => $healthy ? 'ok' : 'degraded',
                'service' => 'microservice',
                'time'    => Timestamp::now(), // always current; only the check results are cached
                'checks'  => [
                    'database' => $database ? 'up' : 'down',
                    'cache'    => $cache ? 'up' : 'down',
                ],
            ]));
    }

    /**
     * The [database, cache] up/down pair, served from a short-lived cache entry
     * when fresh so repeated probes don't hit the DB each time.
     *
     * @return array{0: bool, 1: bool}
     */
    private function readiness(): array
    {
        $store = service('cache');

        try {
            $cached = $store->get('health_readiness');
            if (is_array($cached) && isset($cached['database'], $cached['cache'])) {
                return [(bool) $cached['database'], (bool) $cached['cache']];
            }
        } catch (Throwable) {
            // Cache unavailable — fall through and probe directly.
        }

        $database = $this->checkDatabase();
        $cache    = $this->checkCache();

        try {
            $store->save('health_readiness', ['database' => $database, 'cache' => $cache], self::READINESS_TTL);
        } catch (Throwable) {
            // Best effort — never fail the probe over caching bookkeeping.
        }

        return [$database, $cache];
    }

    private function checkDatabase(): bool
    {
        try {
            Database::connect()->query('SELECT 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function checkCache(): bool
    {
        try {
            $cache = service('cache');
            $probe = 'health_' . bin2hex(random_bytes(4));
            $cache->save($probe, '1', 2);
            $ok = $cache->get($probe) === '1';
            $cache->delete($probe);

            return $ok;
        } catch (Throwable) {
            return false;
        }
    }
}

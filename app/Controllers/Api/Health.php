<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Libraries\ResponseEnvelope;
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
    public function index(): ResponseInterface
    {
        if ($this->request->getGet('probe') === 'live') {
            return $this->response->setStatusCode(200)->setJSON(ResponseEnvelope::wrap([
                'status'  => 'ok',
                'service' => 'microservice',
                'time'    => gmdate('c'),
            ]));
        }

        $database = $this->checkDatabase();
        $cache    = $this->checkCache();
        $healthy  = $database && $cache;

        return $this->response
            ->setStatusCode($healthy ? 200 : 503)
            ->setJSON(ResponseEnvelope::wrap([
                'status'  => $healthy ? 'ok' : 'degraded',
                'service' => 'microservice',
                'time'    => gmdate('c'),
                'checks'  => [
                    'database' => $database ? 'up' : 'down',
                    'cache'    => $cache ? 'up' : 'down',
                ],
            ]));
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

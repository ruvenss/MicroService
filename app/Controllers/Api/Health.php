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
 * Returns a predictable JSON envelope ({data:{...}}) suitable for uptime
 * monitors and n8n HTTP-request nodes. Pings the external database so the
 * check reflects real readiness, not just process liveness.
 */
class Health extends BaseController
{
    public function index(): ResponseInterface
    {
        $databaseUp = $this->checkDatabase();
        $healthy    = $databaseUp;

        return $this->response
            ->setStatusCode($healthy ? 200 : 503)
            ->setJSON(ResponseEnvelope::wrap([
                'status'  => $healthy ? 'ok' : 'degraded',
                'service' => 'microservice',
                'time'    => gmdate('c'),
                'checks'  => [
                    'database' => $databaseUp ? 'up' : 'down',
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
}

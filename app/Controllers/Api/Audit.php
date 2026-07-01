<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Libraries\ResponseEnvelope;
use App\Libraries\Timestamp;
use App\Models\AuditLogModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Read access to the data-mutation audit trail. Requires `audit:read`.
 * Optional filters: ?resource=, ?record_id=, and ?sinceId= (incremental polling).
 */
class Audit extends ApiController
{
    public function index(): ResponseInterface
    {
        if ($denied = $this->requireScope('audit:read')) {
            return $denied;
        }

        $model   = new AuditLogModel();
        $perPage = max(1, min((int) ($this->request->getGet('perPage') ?? 25), 100));
        $page    = max((int) ($this->request->getGet('page') ?? 1), 1);

        if (($resource = $this->request->getGet('resource')) !== null) {
            $model->where('resource', $resource);
        }
        if (($recordId = $this->request->getGet('record_id')) !== null) {
            $model->where('record_id', $recordId);
        }

        // Incremental (change-data-capture) polling: `?sinceId=N` returns only
        // entries after id N, oldest-first, so an n8n schedule can process changes
        // in order and resume from the last id it saw. Without it, newest-first.
        $sinceId     = $this->request->getGet('sinceId');
        $incremental = $sinceId !== null && $sinceId !== '';
        if ($incremental) {
            if (! ctype_digit((string) $sinceId)) {
                return $this->problem(400, 'sinceId must be a non-negative integer.');
            }
            $model->where('id >', (int) $sinceId);
        }

        $total = $model->countAllResults(false);
        $rows  = $model->orderBy('id', $incremental ? 'ASC' : 'DESC')->findAll($perPage, ($page - 1) * $perPage);

        $data = array_map(static fn (array $r): array => [
            'id'         => (int) $r['id'],
            'action'     => $r['action'],
            'resource'   => $r['resource'],
            'record_id'  => $r['record_id'],
            'api_key_id' => $r['api_key_id'] !== null ? (int) $r['api_key_id'] : null,
            'request_id' => $r['request_id'],
            'before'     => $r['before_json'] !== null ? json_decode((string) $r['before_json'], true) : null,
            'after'      => $r['after_json'] !== null ? json_decode((string) $r['after_json'], true) : null,
            'changed'    => $r['changed_json'] !== null ? json_decode((string) $r['changed_json'], true) : null,
            'created_at' => Timestamp::iso($r['created_at']),
        ], $rows);

        return $this->response->setJSON(ResponseEnvelope::collection($data, $page, $perPage, $total));
    }
}

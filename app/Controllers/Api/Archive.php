<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Libraries\AuditWriter;
use App\Libraries\ResourceRegistry;
use App\Libraries\ResponseEnvelope;
use App\Models\ArchivedRecordModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The recycle bin. Lists/inspects archived (deleted) rows and restores them to
 * their original table. Read needs `archive:read`; restore needs `archive:write`.
 */
class Archive extends ApiController
{
    public function index(): ResponseInterface
    {
        if ($denied = $this->requireScope('archive:read')) {
            return $denied;
        }

        $model   = new ArchivedRecordModel();
        $perPage = max(1, min((int) ($this->request->getGet('perPage') ?? 25), 100));
        $page    = max((int) ($this->request->getGet('page') ?? 1), 1);

        if (($resource = $this->request->getGet('resource')) !== null) {
            $model->where('resource', $resource);
        }

        $total = $model->countAllResults(false);
        $rows  = $model->orderBy('deleted_at', 'DESC')->findAll($perPage, ($page - 1) * $perPage);

        $data = array_map(static fn (array $r): array => [
            'id'          => (int) $r['id'],
            'resource'    => $r['resource'],
            'record_id'   => $r['record_id'],
            'deleted_by'  => $r['deleted_by'] !== null ? (int) $r['deleted_by'] : null,
            'deleted_at'  => $r['deleted_at'],
            'restored_at' => $r['restored_at'],
        ], $rows);

        return $this->response->setJSON(ResponseEnvelope::collection($data, $page, $perPage, $total));
    }

    public function show(string $id): ResponseInterface
    {
        if ($denied = $this->requireScope('archive:read')) {
            return $denied;
        }

        $row = (new ArchivedRecordModel())->find($id);
        if ($row === null) {
            return $this->problem(404);
        }

        return $this->response->setJSON(ResponseEnvelope::wrap([
            'id'          => (int) $row['id'],
            'resource'    => $row['resource'],
            'record_id'   => $row['record_id'],
            'payload'     => json_decode((string) $row['payload_json'], true),
            'deleted_by'  => $row['deleted_by'] !== null ? (int) $row['deleted_by'] : null,
            'deleted_at'  => $row['deleted_at'],
            'restored_at' => $row['restored_at'],
        ]));
    }

    public function restore(string $id): ResponseInterface
    {
        if ($denied = $this->requireScope('archive:write')) {
            return $denied;
        }

        $model   = new ArchivedRecordModel();
        $archive = $model->find($id);
        if ($archive === null) {
            return $this->problem(404);
        }
        if ($archive['restored_at'] !== null) {
            return $this->problem(409, 'This record has already been restored.');
        }

        $definition = ResourceRegistry::instance()->get($archive['resource']);
        if ($definition === null) {
            return $this->problem(422, 'The original resource is no longer registered.');
        }

        $payload = json_decode((string) $archive['payload_json'], true);
        if (! is_array($payload)) {
            return $this->problem(422, 'Archived payload is unreadable.');
        }

        $db    = db_connect();
        $pk    = $definition->primaryKey;
        $pkVal = $payload[$pk] ?? null;

        if ($pkVal !== null && $db->table($definition->table)->where($pk, $pkVal)->countAllResults() > 0) {
            return $this->problem(409, 'A record with the original id already exists.');
        }

        $redacted = $definition->hidden === [] ? $payload : array_diff_key($payload, array_flip($definition->hidden));

        $db->transStart();
        $db->table($definition->table)->insert($payload);
        $model->update($archive['id'], ['restored_at' => date('Y-m-d H:i:s')]);
        AuditWriter::record('restore', $definition->slug, $pkVal !== null ? (string) $pkVal : null, null, $redacted);
        $db->transComplete();

        return $this->response->setJSON(ResponseEnvelope::wrap([
            'restored' => true,
            'resource' => $definition->slug,
            'id'       => $pkVal,
        ]));
    }
}

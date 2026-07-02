<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Plugin\ResourceEvent;
use App\Libraries\WebhookDispatcher;
use App\Libraries\AuditWriter;
use App\Libraries\ResourceRegistry;
use App\Libraries\ResponseEnvelope;
use App\Libraries\Timestamp;
use App\Models\ArchivedRecordModel;
use CodeIgniter\Events\Events;
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
        $perPage = $this->pageSize(25, 100);
        $page    = max((int) ($this->request->getGet('page') ?? 1), 1);

        if (($resource = $this->request->getGet('resource')) !== null) {
            $model->where('resource', $resource);
        }

        // Filter by restoration state: `?restored=false` = still-deleted (restorable),
        // `?restored=true` = already restored. Absent = all. Lets an n8n recycle-bin
        // workflow list only what it can actually restore.
        $restored = $this->request->getGet('restored');
        if ($restored !== null && $restored !== '') {
            $isRestored = filter_var($restored, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isRestored === null) {
                return $this->problem(400, 'restored must be true or false.');
            }
            $isRestored ? $model->where('restored_at !=', null) : $model->where('restored_at', null);
        }

        // Deep offsets scan-and-discard — steer to narrowing the window with filters.
        if (($deep = $this->guardDeepOffset($page, $perPage, 'Narrow the window with ?resource= / ?restored= filters.')) !== null) {
            return $deep;
        }

        $total = $model->countAllResults(false);
        $rows  = $model->orderBy('deleted_at', 'DESC')->findAll($perPage, ($page - 1) * $perPage);

        $data = array_map(static fn (array $r): array => [
            'id'          => (int) $r['id'],
            'resource'    => $r['resource'],
            'record_id'   => $r['record_id'],
            'deleted_by'  => $r['deleted_by'] !== null ? (int) $r['deleted_by'] : null,
            'deleted_at'  => Timestamp::iso($r['deleted_at']),
            'restored_at' => Timestamp::iso($r['restored_at']),
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

        // Present the archived record in the same typed, ISO-8601-`Z` shape as a live
        // GET (via the resource's own casts), so an n8n workflow inspecting the recycle
        // bin before restoring parses `payload` exactly like the CRUD response — not raw
        // MySQLi strings. The stored payload_json is untouched (forensic record).
        $payload    = json_decode((string) $row['payload_json'], true);
        $definition = ResourceRegistry::instance()->get($row['resource']);
        if ($definition !== null && is_array($payload)) {
            $payload = $definition->castRow($payload);
        }

        return $this->response->setJSON(ResponseEnvelope::wrap([
            'id'          => (int) $row['id'],
            'resource'    => $row['resource'],
            'record_id'   => $row['record_id'],
            'payload'     => $payload,
            'deleted_by'  => $row['deleted_by'] !== null ? (int) $row['deleted_by'] : null,
            'deleted_at'  => Timestamp::iso($row['deleted_at']),
            'restored_at' => Timestamp::iso($row['restored_at']),
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

        // A row created after this delete may now hold a unique value the archived row
        // carries (e.g. its `sku` was reused). The plain re-insert would trip the unique
        // index — and in production (DBDebug off) that fails *silently*, so without this
        // the restore would roll back yet still report a lying `restored: true`. Pre-check
        // each unique column and refuse with a clear, actionable 409 (nothing changed).
        foreach ($definition->uniqueColumns() as $column) {
            $value = $payload[$column] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if ($db->table($definition->table)->where($column, $value)->countAllResults() > 0) {
                return $this->problem(409, "Cannot restore: {$column} '{$value}' is already in use by another record.");
            }
        }

        $redacted = $definition->hidden === [] ? $payload : array_diff_key($payload, array_flip($definition->hidden));

        $db->transStart();
        $db->table($definition->table)->insert($payload);
        $model->update($archive['id'], ['restored_at' => date('Y-m-d H:i:s')]);
        AuditWriter::record('restore', $definition->slug, $pkVal !== null ? (string) $pkVal : null, null, $redacted);
        // Transactional outbox: enqueue the n8n notification atomically with the restore.
        WebhookDispatcher::enqueue(new ResourceEvent($definition->slug, 'afterRestore', row: $redacted));

        // Backstop: if the write rolled back for any other reason, never report success
        // (and never fire the after-event) — the same transComplete() honesty the CRUD
        // engine enforces via finishTransaction().
        if ($db->transComplete() === false) {
            return $this->problem(409, 'The record could not be restored due to a conflict; nothing was changed.');
        }

        Events::trigger('resource.afterRestore', new ResourceEvent($definition->slug, 'afterRestore', row: $redacted));

        return $this->response->setJSON(ResponseEnvelope::wrap([
            'restored' => true,
            'resource' => $definition->slug,
            'id'       => $pkVal,
        ]));
    }
}

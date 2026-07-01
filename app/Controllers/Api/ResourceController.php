<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Core\Plugin\ResourceEvent;
use App\Libraries\AuditWriter;
use App\Libraries\AuthContext;
use App\Libraries\ProblemDetails;
use App\Libraries\QueryParser;
use App\Libraries\QuerySpec;
use App\Libraries\RequestContext;
use App\Libraries\ResourceDefinition;
use App\Libraries\ResourceRegistry;
use App\Libraries\ResponseEnvelope;
use App\Models\ArchivedRecordModel;
use App\Models\GenericResourceModel;
use CodeIgniter\Events\Events;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Generic CRUD controller. One class serves every registered resource; the
 * resource slug arrives as the first route segment and is resolved against the
 * registry. Unknown slugs return a neutral 404 — indistinguishable from any
 * other not-found, so the registry contents are not probeable.
 */
class ResourceController extends BaseController
{
    /** Maximum items accepted in a single bulk (create/update/delete) request. */
    public const BULK_MAX = 100;

    public function index(string $slug): ResponseInterface
    {
        $definition = $this->resolve($slug);
        if ($definition === null) {
            return $this->notFound();
        }

        $spec = QueryParser::parse($this->request->getGet() ?? [], $definition);
        if (! $spec->isValid()) {
            return $this->problem(400, 'Invalid query parameters.', ['errors' => $spec->errors]);
        }

        $model = $this->model($definition);
        $this->applyFilters($model, $spec->filters);
        $this->fireBeforeQuery($definition, $model);

        $perPage = $this->perPage($definition);

        // Opt-in keyset pagination: any `cursor` query param (even empty) switches
        // to primary-key iteration — no OFFSET/COUNT, stable across inserts.
        if ($this->request->getGet('cursor') !== null) {
            return $this->indexByCursor($definition, $model, $spec, $perPage);
        }

        $page  = max((int) ($this->request->getGet('page') ?? 1), 1);
        $total = $model->countAllResults(false);

        [$column, $direction] = $this->sort($definition);
        $model->orderBy($column, $direction);
        if ($spec->fields !== null) {
            $model->select($spec->fields);
        }
        $rows = $model->findAll($perPage, ($page - 1) * $perPage);

        $rows = array_map(fn (array $row): array => $this->present($row, $definition), $rows);

        return $this->response->setJSON(ResponseEnvelope::collection($rows, $page, $perPage, $total));
    }

    /**
     * Keyset pagination over the primary key. Iterates with `WHERE pk > cursor`
     * (or `<` when descending) instead of OFFSET, so paging stays O(page) and
     * never skips/duplicates rows when the table changes mid-iteration. The client
     * follows `meta.pagination.nextCursor` until it is null.
     */
    private function indexByCursor(ResourceDefinition $definition, GenericResourceModel $model, QuerySpec $spec, int $perPage): ResponseInterface
    {
        $pk = $definition->primaryKey;

        // Keyset needs a unique, ordered key: iterate by the primary key. Honour an
        // explicit ±pk sort for direction; reject any other sort as ambiguous.
        $direction = 'ASC';
        $sortParam = (string) ($this->request->getGet('sort') ?? '');
        if ($sortParam !== '') {
            if (ltrim($sortParam, '-+') !== $pk) {
                return $this->problem(400, "Cursor pagination iterates by {$pk}; drop ?sort or use sort={$pk} / -{$pk}.");
            }
            $direction = str_starts_with($sortParam, '-') ? 'DESC' : 'ASC';
        }

        $cursor = (string) $this->request->getGet('cursor');
        if ($cursor !== '') {
            $lastSeen = self::decodeCursor($cursor);
            if ($lastSeen === null) {
                return $this->problem(400, 'Invalid cursor.');
            }
            $model->where($pk . ($direction === 'DESC' ? ' <' : ' >'), $lastSeen);
        }

        $model->orderBy($pk, $direction);

        // The primary key must be selected to build the next cursor; add it back if
        // a sparse fieldset omitted it, then strip it from the output.
        $stripPk = $spec->fields !== null && ! in_array($pk, $spec->fields, true);
        if ($spec->fields !== null) {
            $model->select($stripPk ? [...$spec->fields, $pk] : $spec->fields);
        }

        // Fetch one extra row to detect whether another page exists.
        $rows    = $model->findAll($perPage + 1, 0);
        $hasMore = count($rows) > $perPage;
        if ($hasMore) {
            array_pop($rows);
        }

        $nextCursor = null;
        if ($hasMore && $rows !== []) {
            $nextCursor = self::encodeCursor((string) $rows[count($rows) - 1][$pk]);
        }

        $rows = array_map(function (array $row) use ($definition, $stripPk, $pk): array {
            $out = $this->present($row, $definition);
            if ($stripPk) {
                unset($out[$pk]);
            }

            return $out;
        }, $rows);

        return $this->response->setJSON(ResponseEnvelope::cursorCollection($rows, $perPage, $nextCursor));
    }

    /** Opaque, versioned cursor token for a primary-key value (not a security boundary). */
    private static function encodeCursor(string $id): string
    {
        return rtrim(strtr(base64_encode('c1|' . $id), '+/', '-_'), '=');
    }

    private static function decodeCursor(string $token): ?string
    {
        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        if ($decoded === false || ! str_starts_with($decoded, 'c1|')) {
            return null;
        }

        return substr($decoded, 3);
    }

    public function show(string $slug, string $id): ResponseInterface
    {
        $definition = $this->resolve($slug);
        if ($definition === null) {
            return $this->notFound();
        }

        $spec = QueryParser::parse($this->request->getGet() ?? [], $definition);
        if (! $spec->isValid()) {
            return $this->problem(400, 'Invalid query parameters.', ['errors' => $spec->errors]);
        }

        $model = $this->model($definition);
        if ($spec->fields !== null) {
            $model->select($spec->fields);
        }
        $row = $model->find($id);
        if ($row === null) {
            return $this->notFound();
        }

        return $this->response->setJSON(ResponseEnvelope::wrap($this->present($row, $definition)));
    }

    public function create(string $slug): ResponseInterface
    {
        $definition = $this->resolve($slug);
        if ($definition === null) {
            return $this->notFound();
        }

        $data = $this->request->getJSON(true);
        if (! is_array($data)) {
            return $this->problem(400, 'Request body must be a JSON object.');
        }

        // A JSON array of objects means bulk-create (all-or-nothing).
        if ($data !== [] && array_is_list($data) && is_array($data[0] ?? null)) {
            return $this->createBulk($definition, $data);
        }

        $data   = $this->fireBeforeSave($definition, 'create', $data);
        $errors = $this->validateAgainst($data, $definition->createRules);
        if ($errors !== []) {
            return $this->validationProblem($errors);
        }

        $model = $this->model($definition);
        $db    = db_connect();

        $db->transStart();
        $id  = $model->insert($this->onlyFillable($data, $definition), true);
        $row = (array) $model->find($id);
        AuditWriter::record('create', $definition->slug, (string) $id, null, $this->hide($row, $definition));
        $db->transComplete();

        $presented = $this->present($row, $definition);
        $this->fireAfter('afterCreate', $definition, $presented);

        return $this->response
            ->setStatusCode(201)
            ->setHeader('Location', site_url("api/v1/{$slug}/{$id}"))
            ->setJSON(ResponseEnvelope::wrap($presented));
    }

    /**
     * Bulk create: validate every item first, then insert them all in one
     * transaction (all-or-nothing). Any invalid item → 422 with per-index errors
     * and nothing is written. Response: { data: [...], meta: { created: N } }.
     * Lets an n8n workflow insert an array of records in a single call.
     *
     * @param list<mixed> $items each element should be an object; non-objects are rejected
     */
    private function createBulk(ResourceDefinition $definition, array $items): ResponseInterface
    {
        if (count($items) > self::BULK_MAX) {
            return $this->problem(422, 'Bulk create is limited to ' . self::BULK_MAX . ' items per request.');
        }

        $errors   = [];
        $prepared = [];
        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                $errors[$index] = ['_' => 'Each item must be a JSON object.'];

                continue;
            }
            $item      = $this->fireBeforeSave($definition, 'create', $item);
            $itemError = $this->validateAgainst($item, $definition->createRules);
            if ($itemError !== []) {
                $errors[$index] = $itemError;
            } else {
                $prepared[] = $this->onlyFillable($item, $definition);
            }
        }

        if ($errors !== []) {
            return $this->problem(422, 'One or more items are invalid.', ['errors' => $errors]);
        }

        $model = $this->model($definition);
        $db    = db_connect();

        $db->transStart();
        $created = [];
        foreach ($prepared as $clean) {
            $id = $model->insert($clean, true);
            if ($id === false) {
                $db->transComplete();

                return $this->problem(409, 'Bulk create failed; no records were created.');
            }
            $row = (array) $model->find($id);
            AuditWriter::record('create', $definition->slug, (string) $id, null, $this->hide($row, $definition));
            $created[] = $this->present($row, $definition);
        }
        $db->transComplete();

        foreach ($created as $createdRow) {
            $this->fireAfter('afterCreate', $definition, $createdRow);
        }

        return $this->response->setStatusCode(201)->setJSON([
            'data' => $created,
            'meta' => ['created' => count($created)],
        ]);
    }

    /**
     * Collection-level PATCH: bulk update. Body is a JSON array of objects, each
     * carrying its primary key plus the fields to change. Lets an n8n workflow
     * update many rows in one call.
     */
    public function updateCollection(string $slug): ResponseInterface
    {
        $definition = $this->resolve($slug);
        if ($definition === null) {
            return $this->notFound();
        }

        $items = $this->request->getJSON(true);
        if (! is_array($items) || $items === [] || ! array_is_list($items)) {
            return $this->problem(400, 'Request body must be a non-empty JSON array of objects.');
        }

        return $this->updateBulk($definition, $items);
    }

    /**
     * Collection-level DELETE: bulk archival delete. Body is either a JSON array
     * of ids or an object `{"ids": [...]}`. Every row is archived (restorable) in
     * one all-or-nothing transaction.
     */
    public function deleteCollection(string $slug): ResponseInterface
    {
        $definition = $this->resolve($slug);
        if ($definition === null) {
            return $this->notFound();
        }

        $body = $this->request->getJSON(true);
        $ids  = is_array($body) && array_is_list($body) ? $body : ($body['ids'] ?? null);
        if (! is_array($ids)) {
            return $this->problem(400, 'Provide ids as a JSON array, or an object with an "ids" array.');
        }

        return $this->deleteBulk($definition, array_values($ids));
    }

    /**
     * Validate every item first (each must exist and pass update rules), then
     * apply them all in one transaction (all-or-nothing). Any invalid item → 422
     * with per-index errors and nothing is written.
     * Response: { data: [...updated...], meta: { updated: N } }.
     *
     * @param list<mixed> $items each element should be an object carrying the primary key
     */
    private function updateBulk(ResourceDefinition $definition, array $items): ResponseInterface
    {
        if (count($items) > self::BULK_MAX) {
            return $this->problem(422, 'Bulk update is limited to ' . self::BULK_MAX . ' items per request.');
        }

        $model  = $this->model($definition);
        $pk     = $definition->primaryKey;
        $errors = [];
        $plan   = [];
        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                $errors[$index] = ['_' => 'Each item must be a JSON object.'];

                continue;
            }
            $id = $item[$pk] ?? null;
            if ($id === null || $id === '') {
                $errors[$index] = ['_' => "Each item must include its {$pk}."];

                continue;
            }
            $before = $model->find($id);
            if ($before === null) {
                $errors[$index] = [$pk => 'No record matches this identifier.'];

                continue;
            }
            $data      = $this->fireBeforeSave($definition, 'update', $item);
            $itemError = $this->validateAgainst($data, $definition->updateRules);
            if ($itemError !== []) {
                $errors[$index] = $itemError;

                continue;
            }
            $clean = $this->onlyFillable($data, $definition);
            if ($clean === []) {
                $errors[$index] = ['_' => 'No updatable fields provided.'];

                continue;
            }
            $plan[] = [(string) $id, $before, $clean];
        }

        if ($errors !== []) {
            return $this->problem(422, 'One or more items are invalid.', ['errors' => $errors]);
        }

        $db = db_connect();
        $db->transStart();
        $updated = [];
        foreach ($plan as [$id, $before, $clean]) {
            $model->update($id, $clean);
            $after = (array) $model->find($id);
            AuditWriter::record('update', $definition->slug, $id, $this->hide($before, $definition), $this->hide($after, $definition));
            $updated[] = [$this->present($after, $definition), $this->hide($before, $definition)];
        }
        $db->transComplete();

        $data = [];
        foreach ($updated as [$row, $beforeHidden]) {
            $this->fireAfter('afterUpdate', $definition, $row, $beforeHidden);
            $data[] = $row;
        }

        return $this->response->setJSON(['data' => $data, 'meta' => ['updated' => count($data)]]);
    }

    /**
     * Archive-and-delete every id in one transaction (all-or-nothing). Any id that
     * does not resolve → 422 with per-index errors and nothing is deleted.
     * Response: { meta: { deleted: N } }.
     *
     * @param list<mixed> $ids
     */
    private function deleteBulk(ResourceDefinition $definition, array $ids): ResponseInterface
    {
        if ($ids === []) {
            return $this->problem(422, 'Provide a non-empty list of ids to delete.');
        }
        if (count($ids) > self::BULK_MAX) {
            return $this->problem(422, 'Bulk delete is limited to ' . self::BULK_MAX . ' items per request.');
        }

        $model  = $this->model($definition);
        $errors = [];
        $rows   = [];
        foreach ($ids as $index => $id) {
            if (! is_scalar($id) || (string) $id === '') {
                $errors[$index] = ['_' => 'Each id must be a non-empty scalar value.'];

                continue;
            }
            $row = $model->find($id);
            if ($row === null) {
                $errors[$index] = ['_' => 'No record matches this identifier.'];

                continue;
            }
            $rows[(string) $id] = $row;
        }

        if ($errors !== []) {
            return $this->problem(422, 'One or more ids are invalid.', ['errors' => $errors]);
        }

        $db      = db_connect();
        $archive = new ArchivedRecordModel();
        $db->transStart();
        foreach ($rows as $id => $row) {
            $this->archiveAndDelete($definition, (string) $id, $row, $model, $archive);
        }
        $db->transComplete();

        foreach ($rows as $row) {
            $this->fireAfter('afterDelete', $definition, $this->hide($row, $definition));
        }

        return $this->response->setJSON(['meta' => ['deleted' => count($rows)]]);
    }

    public function update(string $slug, string $id): ResponseInterface
    {
        $definition = $this->resolve($slug);
        if ($definition === null) {
            return $this->notFound();
        }

        $model  = $this->model($definition);
        $before = $model->find($id);
        if ($before === null) {
            return $this->notFound();
        }

        $data = $this->request->getJSON(true);
        if (! is_array($data)) {
            return $this->problem(400, 'Request body must be a JSON object.');
        }

        $data   = $this->fireBeforeSave($definition, 'update', $data);
        $errors = $this->validateAgainst($data, $definition->updateRules);
        if ($errors !== []) {
            return $this->validationProblem($errors);
        }

        $db = db_connect();
        $db->transStart();
        $model->update($id, $this->onlyFillable($data, $definition));
        $after = (array) $model->find($id);
        AuditWriter::record('update', $definition->slug, (string) $id, $this->hide($before, $definition), $this->hide($after, $definition));
        $db->transComplete();

        $presented = $this->present($after, $definition);
        $this->fireAfter('afterUpdate', $definition, $presented, $this->hide($before, $definition));

        return $this->response->setJSON(ResponseEnvelope::wrap($presented));
    }

    /**
     * Archival delete: the row is moved to the recycle bin (archived_records)
     * and audited, then removed from its table — all in one transaction. It can
     * be restored later via POST /api/v1/_archive/{id}/restore.
     */
    public function delete(string $slug, string $id): ResponseInterface
    {
        $definition = $this->resolve($slug);
        if ($definition === null) {
            return $this->notFound();
        }

        $model = $this->model($definition);
        $row   = $model->find($id);
        if ($row === null) {
            return $this->notFound();
        }

        $db = db_connect();
        $db->transStart();
        $this->archiveAndDelete($definition, (string) $id, $row, $model, new ArchivedRecordModel());
        $db->transComplete();

        $this->fireAfter('afterDelete', $definition, $this->hide($row, $definition));

        return $this->response->setStatusCode(204);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * Move one row to the recycle bin and remove it from its table, recording the
     * mutation. Callers wrap this in a transaction and fire afterDelete post-commit.
     *
     * @param array<string, mixed> $row
     */
    private function archiveAndDelete(ResourceDefinition $definition, string $id, array $row, GenericResourceModel $model, ArchivedRecordModel $archive): void
    {
        $archive->insert([
            'resource'     => $definition->slug,
            'source_table' => $definition->table,
            'record_id'    => $id,
            'payload_json' => json_encode($row),
            'deleted_by'   => AuthContext::keyId(),
            'request_id'   => RequestContext::id(),
            'deleted_at'   => date('Y-m-d H:i:s'),
        ]);
        $model->delete($id);
        AuditWriter::record('delete', $definition->slug, $id, $this->hide($row, $definition), null);
    }

    private function resolve(string $slug): ?ResourceDefinition
    {
        return ResourceRegistry::instance()->get($slug);
    }

    private function model(ResourceDefinition $definition): GenericResourceModel
    {
        return (new GenericResourceModel())->forResource($definition);
    }

    /**
     * Apply parsed filters to the model's query builder. Columns are already
     * allow-listed by the parser; values are bound by the builder.
     *
     * @param list<array{column: string, operator: string, value: mixed}> $filters
     */
    private function applyFilters(GenericResourceModel $model, array $filters): void
    {
        foreach ($filters as $filter) {
            $column = $filter['column'];
            $value  = $filter['value'];

            match ($filter['operator']) {
                'ne'    => $model->where("{$column} !=", $value),
                'gt'    => $model->where("{$column} >", $value),
                'gte'   => $model->where("{$column} >=", $value),
                'lt'    => $model->where("{$column} <", $value),
                'lte'   => $model->where("{$column} <=", $value),
                'like'  => $model->like($column, is_array($value) ? implode(',', $value) : (string) $value),
                'in'    => $model->whereIn($column, is_array($value) ? $value : [$value]),
                default => $model->where($column, $value), // 'eq'
            };
        }
    }

    private function perPage(ResourceDefinition $definition): int
    {
        $requested = (int) ($this->request->getGet('perPage') ?? $definition->perPageDefault);

        return max(1, min($requested, $definition->perPageMax));
    }

    /**
     * @return array{0: string, 1: string} [column, direction]
     */
    private function sort(ResourceDefinition $definition): array
    {
        $candidate = (string) ($this->request->getGet('sort') ?? '');
        if ($candidate === '') {
            $candidate = $definition->defaultSort;
        }

        $column = ltrim($candidate, '-+');
        if (! in_array($column, $definition->sortable, true)) {
            $candidate = $definition->defaultSort;
            $column    = ltrim($candidate, '-+');
        }

        return [$column, str_starts_with($candidate, '-') ? 'DESC' : 'ASC'];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function onlyFillable(array $data, ResourceDefinition $definition): array
    {
        return array_intersect_key($data, array_flip($definition->fillable));
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function hide(array $row, ResourceDefinition $definition): array
    {
        return $definition->hidden === [] ? $row : array_diff_key($row, array_flip($definition->hidden));
    }

    /**
     * Hide secret fields, then let plugins transform the outgoing row
     * (resource.serialize) — e.g. add computed fields or cast types for n8n.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function present(array $row, ResourceDefinition $definition): array
    {
        $event = new ResourceEvent($definition->slug, 'serialize', row: $this->cast($this->hide($row, $definition), $definition));
        Events::trigger('resource.serialize', $event);

        return $event->row;
    }

    /**
     * Cast output columns to their declared types so responses carry proper JSON
     * types (int/float/bool) instead of MySQLi's all-strings — friendlier for n8n.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function cast(array $row, ResourceDefinition $definition): array
    {
        foreach ($definition->casts as $field => $type) {
            if (! array_key_exists($field, $row) || $row[$field] === null) {
                continue;
            }
            $row[$field] = match ($type) {
                'int'   => (int) $row[$field],
                'float' => (float) $row[$field],
                'bool'  => (bool) $row[$field],
                'string' => (string) $row[$field],
                default => $row[$field],
            };
        }

        return $row;
    }

    /**
     * Let plugins mutate a write payload before validation (resource.beforeSave)
     * — e.g. derive fields or enforce defaults.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function fireBeforeSave(ResourceDefinition $definition, string $action, array $data): array
    {
        $event = new ResourceEvent($definition->slug, $action, data: $data);
        Events::trigger('resource.beforeSave', $event);

        return $event->data;
    }

    /**
     * Let plugins constrain the list query (resource.beforeQuery) — e.g. tenant
     * scoping. Listeners add conditions to the shared model instance.
     */
    private function fireBeforeQuery(ResourceDefinition $definition, GenericResourceModel $model): void
    {
        Events::trigger('resource.beforeQuery', new ResourceEvent($definition->slug, 'list', model: $model));
    }

    /**
     * Fire a post-commit mutation event (resource.afterCreate/afterUpdate/
     * afterDelete). Plugins subscribe to react to durable changes — e.g. POST to
     * an n8n webhook, invalidate a cache, or cascade. `$row` is the presented row
     * (the client's view); `$before` carries the prior state for updates.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $before
     */
    private function fireAfter(string $action, ResourceDefinition $definition, array $row, array $before = []): void
    {
        Events::trigger('resource.' . $action, new ResourceEvent($definition->slug, $action, data: $before, row: $row));
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $rules
     *
     * @return array<string, string> field => message (empty when valid)
     */
    private function validateAgainst(array $data, array $rules): array
    {
        if ($rules === []) {
            return [];
        }

        $validation = service('validation');
        $validation->setRules($rules);

        return $validation->run($data) ? [] : $validation->getErrors();
    }

    private function notFound(): ResponseInterface
    {
        return $this->problem(404, null);
    }

    /**
     * @param array<string, string> $errors
     */
    private function validationProblem(array $errors): ResponseInterface
    {
        return $this->problem(422, 'One or more fields are invalid.', ['errors' => $errors]);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function problem(int $status, ?string $detail, array $extra = []): ResponseInterface
    {
        $body = ProblemDetails::make($status, $detail, ['requestId' => RequestContext::id()] + $extra);

        return $this->response
            ->setStatusCode($status)
            ->setBody((string) json_encode($body))
            ->setContentType('application/problem+json');
    }
}

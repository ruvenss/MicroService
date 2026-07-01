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
use App\Libraries\WebhookDispatcher;
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

    /**
     * JSON flags for the manually-encoded GET bodies (respondCacheable) and the
     * ETag hash, matching what CI4's setJSON uses for writes via Config\Format:
     * raw UTF-8 and unescaped slashes. Without this, reads escaped `café` → `café`
     * and `a/b` → `a\/b` while writes did not — a mismatched, bulkier wire format
     * and unreadable output for a human testing GETs in Postman.
     */
    private const RESPONSE_JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

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

        foreach ($this->sortColumns($definition) as [$column, $direction]) {
            $model->orderBy($column, $direction);
        }
        if ($spec->fields !== null) {
            $model->select($spec->fields);
        }
        $rows = $model->findAll($perPage, ($page - 1) * $perPage);

        $rows = array_map(fn (array $row): array => $this->present($row, $definition), $rows);

        $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 1;
        $rels       = [];
        if ($page < $totalPages) {
            $rels['next'] = ['page' => $page + 1];
        }
        if ($page > 1) {
            $rels['prev'] = ['page' => $page - 1];
        }
        $rels['first'] = ['page' => 1];
        $rels['last']  = ['page' => max(1, $totalPages)];
        $this->response->setHeader('Link', $this->linkHeader($rels));

        return $this->respondCacheable(ResponseEnvelope::collection($rows, $page, $perPage, $total));
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

        if ($nextCursor !== null) {
            $this->response->setHeader('Link', $this->linkHeader(['next' => ['cursor' => $nextCursor]]));
        }

        return $this->respondCacheable(ResponseEnvelope::cursorCollection($rows, $perPage, $nextCursor));
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

        return $this->respondCacheable(ResponseEnvelope::wrap($this->present($row, $definition)));
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
        $id       = $model->insert($this->onlyFillable($data, $definition), true);
        $row      = (array) $model->find($id);
        $presented = $this->present($row, $definition);
        AuditWriter::record('create', $definition->slug, (string) $id, null, $this->hide($row, $definition));
        $this->enqueueWebhook('afterCreate', $definition, $presented);
        $db->transComplete();

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
            $row       = (array) $model->find($id);
            $presented = $this->present($row, $definition);
            AuditWriter::record('create', $definition->slug, (string) $id, null, $this->hide($row, $definition));
            $this->enqueueWebhook('afterCreate', $definition, $presented);
            $created[] = $presented;
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
     * Collection-level PUT: upsert (create-or-update) by the resource's declared
     * natural key (`upsertKey`). Body is a single object or a JSON array. Each item
     * is matched by its key value: found → update, absent → create. All-or-nothing
     * in one transaction. Lets an n8n sync workflow reconcile records in one
     * idempotent call instead of GET-then-POST/PATCH (which races).
     */
    public function upsertCollection(string $slug): ResponseInterface
    {
        $definition = $this->resolve($slug);
        if ($definition === null) {
            return $this->notFound();
        }
        if ($definition->upsertKey === null) {
            return $this->problem(422, 'Upsert is not supported for this resource.');
        }

        $body = $this->request->getJSON(true);
        if (! is_array($body) || $body === []) {
            return $this->problem(400, 'Request body must be a JSON object or a non-empty array of objects.');
        }
        // Normalise single object → one-element list; keep a flag for the response shape.
        $isSingle = ! array_is_list($body);
        $items    = $isSingle ? [$body] : $body;

        return $this->upsert($definition, $items, $isSingle);
    }

    /**
     * Plan every item (match by key → create or update, validate the right rule
     * set) then apply the plan in one transaction. Any invalid/duplicate item →
     * 422 and nothing is written.
     *
     * @param list<mixed> $items
     */
    private function upsert(ResourceDefinition $definition, array $items, bool $isSingle): ResponseInterface
    {
        if (count($items) > self::BULK_MAX) {
            return $this->problem(422, 'Upsert is limited to ' . self::BULK_MAX . ' items per request.');
        }

        $key    = $definition->upsertKey;
        $model  = $this->model($definition);
        $errors = [];
        $seen   = [];
        $plan   = []; // [ 'update'|'create', ?before, cleanData ]

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                $errors[$index] = ['_' => 'Each item must be a JSON object.'];

                continue;
            }
            $keyValue = $item[$key] ?? null;
            if ($keyValue === null || $keyValue === '') {
                $errors[$index] = [$key => "Each item must include its {$key}."];

                continue;
            }
            if (isset($seen[$keyValue])) {
                $errors[$index] = [$key => "Duplicate {$key} in the same request."];

                continue;
            }
            $seen[$keyValue] = true;

            $item     = $this->fireBeforeSave($definition, 'upsert', $item);
            $existing = $model->where($key, $keyValue)->first();

            if ($existing !== null) {
                $itemError = $this->validateAgainst($item, $definition->updateRules);
                if ($itemError !== []) {
                    $errors[$index] = $itemError;

                    continue;
                }
                $plan[] = ['update', $existing, $this->onlyFillable($item, $definition)];
            } else {
                $itemError = $this->validateAgainst($item, $definition->createRules);
                if ($itemError !== []) {
                    $errors[$index] = $itemError;

                    continue;
                }
                $plan[] = ['create', null, $this->onlyFillable($item, $definition)];
            }
        }

        if ($errors !== []) {
            return $this->problem(422, 'One or more items are invalid.', ['errors' => $errors]);
        }

        $db = db_connect();
        $db->transStart();
        $rows    = [];
        $created = 0;
        $updated = 0;
        foreach ($plan as [$op, $before, $clean]) {
            if ($op === 'create') {
                $id  = $model->insert($clean, true);
                $row = (array) $model->find($id);
                $presented = $this->present($row, $definition);
                AuditWriter::record('create', $definition->slug, (string) $id, null, $this->hide($row, $definition));
                $this->enqueueWebhook('afterCreate', $definition, $presented);
                $rows[] = ['afterCreate', $presented, []];
                $created++;
            } else {
                $id           = $before[$definition->primaryKey];
                $model->update($id, $clean);
                $after        = (array) $model->find($id);
                $beforeHidden = $this->hide($before, $definition);
                $presented    = $this->present($after, $definition);
                AuditWriter::record('update', $definition->slug, (string) $id, $beforeHidden, $this->hide($after, $definition));
                $this->enqueueWebhook('afterUpdate', $definition, $presented, $beforeHidden);
                $rows[] = ['afterUpdate', $presented, $beforeHidden];
                $updated++;
            }
        }
        $db->transComplete();

        $data = [];
        foreach ($rows as [$action, $row, $before]) {
            $this->fireAfter($action, $definition, $row, $before);
            $data[] = $row;
        }

        $meta = ['upserted' => count($data), 'created' => $created, 'updated' => $updated];

        return $this->response
            ->setStatusCode($created > 0 && $updated === 0 ? 201 : 200)
            ->setJSON($isSingle ? ResponseEnvelope::wrap($data[0], $meta) : ['data' => $data, 'meta' => $meta]);
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
            $after        = (array) $model->find($id);
            $presented    = $this->present($after, $definition);
            $beforeHidden = $this->hide($before, $definition);
            AuditWriter::record('update', $definition->slug, $id, $beforeHidden, $this->hide($after, $definition));
            $this->enqueueWebhook('afterUpdate', $definition, $presented, $beforeHidden);
            $updated[] = [$presented, $beforeHidden];
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

        // A plugin veto on any row aborts the whole batch (all-or-nothing).
        foreach ($rows as $rowId => $row) {
            $veto = $this->fireBeforeDelete($definition, $this->hide($row, $definition));
            if ($veto !== null) {
                $errors[$rowId] = ['_' => $veto];
            }
        }
        if ($errors !== []) {
            return $this->problem(409, 'One or more deletes were not permitted.', ['errors' => $errors]);
        }

        $db      = db_connect();
        $archive = new ArchivedRecordModel();
        $db->transStart();
        foreach ($rows as $id => $row) {
            $this->archiveAndDelete($definition, (string) $id, $row, $model, $archive);
            $this->enqueueWebhook('afterDelete', $definition, $this->hide($row, $definition));
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

        $precondition = $this->ifMatchFails($before, $definition);
        if ($precondition !== null) {
            return $precondition;
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
        $after        = (array) $model->find($id);
        $beforeHidden = $this->hide($before, $definition);
        $presented    = $this->present($after, $definition);
        AuditWriter::record('update', $definition->slug, (string) $id, $beforeHidden, $this->hide($after, $definition));
        $this->enqueueWebhook('afterUpdate', $definition, $presented, $beforeHidden);
        $db->transComplete();

        $this->fireAfter('afterUpdate', $definition, $presented, $beforeHidden);

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

        $precondition = $this->ifMatchFails($row, $definition);
        if ($precondition !== null) {
            return $precondition;
        }

        $veto = $this->fireBeforeDelete($definition, $this->hide($row, $definition));
        if ($veto !== null) {
            return $this->problem(409, $veto);
        }

        $db = db_connect();
        $db->transStart();
        $this->archiveAndDelete($definition, (string) $id, $row, $model, new ArchivedRecordModel());
        $this->enqueueWebhook('afterDelete', $definition, $this->hide($row, $definition));
        $db->transComplete();

        $this->fireAfter('afterDelete', $definition, $this->hide($row, $definition));

        return $this->response->setStatusCode(204);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * Emit a GET payload with an ETag for conditional requests. A matching
     * `If-None-Match` short-circuits to 304 Not Modified (empty body), so an n8n
     * poll that sees no change transfers nothing. The tag is a content hash — it
     * carries no engine fingerprint and needs no stored state.
     *
     * @param array<string, mixed> $payload
     */
    private function respondCacheable(array $payload): ResponseInterface
    {
        $body = (string) json_encode($payload, self::RESPONSE_JSON_FLAGS);
        $etag = '"' . sha1($body) . '"';

        $ifNoneMatch = $this->request->getHeaderLine('If-None-Match');
        if ($ifNoneMatch !== '' && $this->etagMatches($ifNoneMatch, $etag)) {
            return $this->response->setStatusCode(304)->setHeader('ETag', $etag)->setBody('');
        }

        return $this->response
            ->setHeader('ETag', $etag)
            ->setContentType('application/json')
            ->setBody($body);
    }

    /**
     * RFC 9110 If-None-Match: `*` matches anything; otherwise a comma-separated
     * list of entity-tags. Weak validators (`W/"…"`) compare equal to their strong
     * form here since our tags are stable content hashes.
     */
    private function etagMatches(string $ifNoneMatch, string $etag): bool
    {
        if (trim($ifNoneMatch) === '*') {
            return true;
        }

        foreach (explode(',', $ifNoneMatch) as $candidate) {
            $candidate = preg_replace('/^\s*W\//', '', trim($candidate));
            if ($candidate === $etag) {
                return true;
            }
        }

        return false;
    }

    /** The ETag a GET of this single row would carry (same recipe as respondCacheable). */
    private function etagForResource(array $row, ResourceDefinition $definition): string
    {
        return '"' . sha1((string) json_encode(ResponseEnvelope::wrap($this->present($row, $definition)), self::RESPONSE_JSON_FLAGS)) . '"';
    }

    /**
     * Optimistic concurrency (RFC 9110): if the request carries `If-Match`, the
     * current row's ETag must match, else 412 — so two n8n workflows editing the
     * same record can't silently clobber each other (lost update). No header =
     * unconditional; `If-Match: *` matches any existing row.
     *
     * @param array<string, mixed> $row
     */
    private function ifMatchFails(array $row, ResourceDefinition $definition): ?ResponseInterface
    {
        $ifMatch = $this->request->getHeaderLine('If-Match');
        if ($ifMatch === '' || trim($ifMatch) === '*') {
            return null;
        }

        if ($this->etagMatches($ifMatch, $this->etagForResource($row, $definition))) {
            return null;
        }

        return $this->problem(412, 'The resource has changed since it was fetched (If-Match precondition failed).');
    }

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
                'like'  => $model->like($column, $this->escapeLikeWildcards(is_array($value) ? implode(',', $value) : (string) $value)),
                'in'    => $model->whereIn($column, is_array($value) ? $value : [$value]),
                'nin'   => $model->whereNotIn($column, is_array($value) ? $value : [$value]),
                default => $model->where($column, $value), // 'eq'
            };
        }
    }

    /**
     * Escape a caller's `%` and `_` before they reach a `LIKE` so they are matched
     * literally, not as wildcards. Without this, `filter[col][like]=%` (or `_`)
     * expands to a match-everything `LIKE '%%%'` — wrong results, and a full-table
     * scan an attacker could weaponise against the external DB (a DoS lever on an
     * exposed service). CI4 emits `ESCAPE '!'` and `escapeLikeString` uses `!` as
     * the escape char, so the two line up; value quoting (SQL-injection defence) is
     * unaffected — `$model->like()` still binds the value with default escaping.
     */
    private function escapeLikeWildcards(string $value): string
    {
        return db_connect()->escapeLikeString($value);
    }

    private function perPage(ResourceDefinition $definition): int
    {
        $requested = (int) ($this->request->getGet('perPage') ?? $definition->perPageDefault);

        return max(1, min($requested, $definition->perPageMax));
    }

    /**
     * RFC 8288 `Link` header for a list response, so a client — e.g. n8n's HTTP node
     * "next URL from header" pagination — can follow next/prev without reconstructing
     * the query. URLs are relative (resolved against the request), preserving every
     * other param (filter/sort/fields/perPage); each `rel` overrides only page/cursor.
     *
     * @param array<string, array<string, int|string>> $rels rel => query overrides
     */
    private function linkHeader(array $rels): string
    {
        // Strip the front controller — Apache rewrites clean URLs to `index.php/…`, so
        // getPath() carries it; a `/index.php/` in the Link would both leak PHP and be
        // a non-clean URL. Keeps any real base-path prefix intact.
        $path  = (string) preg_replace('#/index\.php(?=/|$)#', '', $this->request->getUri()->getPath());
        $path  = '/' . ltrim($path, '/');
        $query = $this->request->getGet() ?? [];

        $parts = [];
        foreach ($rels as $rel => $override) {
            $qs      = http_build_query(array_merge($query, $override));
            $parts[] = '<' . $path . ($qs !== '' ? '?' . $qs : '') . '>; rel="' . $rel . '"';
        }

        return implode(', ', $parts);
    }

    /**
     * Parse `?sort=col,-col2,…` into an ordered list of [column, direction] pairs
     * (allow-listed against `sortable`), so a client can sort by multiple columns.
     *
     * @return list<array{0: string, 1: string}> ordered [column, direction] pairs
     */
    private function sortColumns(ResourceDefinition $definition): array
    {
        $raw    = trim((string) ($this->request->getGet('sort') ?? ''));
        $tokens = $raw === '' ? [] : array_filter(array_map('trim', explode(',', $raw)), static fn (string $t): bool => $t !== '');

        // Apply each requested column on the allow-list (the primary key is always
        // sortable — the tiebreaker/cursor key), in order; a `-` prefix means
        // descending. Unknown columns never reach here: QueryParser already 400'd
        // them at the spec gate, so they are never interpolated.
        $orders = [];
        foreach ($tokens as $token) {
            $column = ltrim($token, '-+');
            if ($column !== '' && (in_array($column, $definition->sortable, true) || $column === $definition->primaryKey)) {
                $orders[$column] = [$column, str_starts_with($token, '-') ? 'DESC' : 'ASC'];
            }
        }

        if ($orders === []) {
            // Fall back to the resource's declared default sort.
            $default             = $definition->defaultSort;
            $column              = ltrim($default, '-+');
            $orders[$column]     = [$column, str_starts_with($default, '-') ? 'DESC' : 'ASC'];
        }

        // Always break ties on the primary key so the ordering is a total order —
        // otherwise rows equal on a non-unique sort column (e.g. price, created_at)
        // have undefined order and offset pagination can skip/duplicate rows across
        // pages. Harmless when the caller already sorts by the primary key.
        if (! isset($orders[$definition->primaryKey])) {
            // Match the tiebreaker to the least-significant sort column's direction so
            // a `(col, id)` index satisfies the whole ORDER BY in one (possibly reverse)
            // scan rather than a filesort — e.g. the default `-created_at` becomes
            // `created_at DESC, id DESC`, index-backed. Any consistent direction gives a
            // stable total order; aligning it just makes the common single-column sort fast.
            $lastDirection                   = end($orders)[1];
            $orders[$definition->primaryKey] = [$definition->primaryKey, $lastDirection];
        }

        return array_values($orders);
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
     * Delegates to the resource definition so the CRUD response and the `_audit`
     * change feed present the same record identically.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function cast(array $row, ResourceDefinition $definition): array
    {
        return $definition->castRow($row);
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
     * Give plugins a vetoable say before a row is archived (resource.beforeDelete)
     * — e.g. refuse to delete a record still referenced elsewhere. Returns the veto
     * reason when a listener cancelled, or null to proceed.
     *
     * @param array<string, mixed> $row the hidden row about to be deleted
     */
    private function fireBeforeDelete(ResourceDefinition $definition, array $row): ?string
    {
        $event = new ResourceEvent($definition->slug, 'delete', row: $row);
        Events::trigger('resource.beforeDelete', $event);

        return $event->isCancelled() ? ($event->cancelReason() ?? 'Operation not permitted.') : null;
    }

    /**
     * Transactional outbox: enqueue matching webhook rows for a mutation *inside*
     * the same DB transaction as the change itself, so the notification and the
     * data commit atomically (or roll back together). This closes the dual-write
     * gap where a crash after commit but before enqueue would silently drop an
     * n8n notification. Delivery stays out-of-band via `webhooks:dispatch`.
     *
     * @param array<string, mixed> $row    the presented row (client's view)
     * @param array<string, mixed> $before prior state, for updates
     */
    private function enqueueWebhook(string $action, ResourceDefinition $definition, array $row, array $before = []): void
    {
        // Type the payload at the one choke point that builds every outbound webhook,
        // so `data` and `previous` reach n8n in the same int/float/bool + ISO-8601-`Z`
        // shape as a live GET — regardless of whether the caller passed an already
        // presented row (create/update) or a raw hidden row (delete `data`, update
        // `previous`). castRow is idempotent (re-casting cast values is a no-op) and
        // never re-fires the serialize hook, so presented rows pass through unchanged.
        $row    = $definition->castRow($row);
        $before = $before === [] ? [] : $definition->castRow($before);
        WebhookDispatcher::enqueue(new ResourceEvent($definition->slug, $action, data: $before, row: $row));
    }

    /**
     * Fire a post-commit mutation event (resource.afterCreate/afterUpdate/
     * afterDelete). Plugins subscribe to react to durable changes — e.g. invalidate
     * a cache or cascade. `$row` is the presented row (the client's view); `$before`
     * carries the prior state for updates. (Webhook enqueue is transactional — see
     * enqueueWebhook — so it is intentionally not driven from here.)
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

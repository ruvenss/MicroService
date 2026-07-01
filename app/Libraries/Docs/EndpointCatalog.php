<?php

declare(strict_types=1);

namespace App\Libraries\Docs;

use App\Controllers\Api\ResourceController;
use App\Libraries\QueryParser;
use App\Libraries\ResourceDefinition;
use App\Libraries\ResourceRegistry;

/**
 * Single source of truth for the API surface, introspected from the resource
 * registry plus the fixed meta endpoints. The OpenAPI, Markdown, and Postman
 * generators all consume this, so the three artefacts can never drift.
 *
 * Each endpoint descriptor:
 *   tag, resource, method, path, operationId, summary, auth(bool), scope(?string),
 *   pathParams(list<string>), query(list<array{name,description}>),
 *   body(?array<string,array{type,required,enum,example}>), bodyExample(?string raw JSON),
 *   success(int), successKind(item|collection|meta|none), captureId(bool)
 */
final class EndpointCatalog
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function all(?ResourceRegistry $registry = null): array
    {
        $registry ??= ResourceRegistry::instance();
        $endpoints = self::meta();

        foreach ($registry->slugs() as $slug) {
            $definition = $registry->get($slug);
            if ($definition !== null) {
                array_push($endpoints, ...self::forResource($definition));
            }
        }

        return $endpoints;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function meta(): array
    {
        return [
            [
                'tag' => 'System', 'resource' => null, 'method' => 'GET', 'path' => '/api/v1/health',
                'operationId' => 'health', 'summary' => 'Health probe: readiness (default, checks database + cache; 503 if degraded) or liveness.',
                'auth' => false, 'scope' => null, 'pathParams' => [],
                'query' => [['name' => 'probe', 'description' => 'live = process-only liveness (always 200, no dependencies); ready (default) = readiness, pings database + cache.']],
                'body' => null, 'success' => 200, 'successKind' => 'item', 'captureId' => false,
            ],
            [
                'tag' => 'System', 'resource' => null, 'method' => 'GET', 'path' => '/api/v1/_me',
                'operationId' => 'whoAmI', 'summary' => 'Introspect the authenticated API key (name, scopes, limits) — no secret.',
                'auth' => true, 'scope' => null, 'pathParams' => [], 'query' => [], 'body' => null,
                'success' => 200, 'successKind' => 'item', 'captureId' => false,
            ],
            [
                'tag' => 'System', 'resource' => null, 'method' => 'GET', 'path' => '/api/v1/_resources',
                'operationId' => 'discoverResources', 'summary' => 'Discover registered resources and how to query them.',
                'auth' => true, 'scope' => null, 'pathParams' => [], 'query' => [], 'body' => null,
                'success' => 200, 'successKind' => 'collection', 'captureId' => false,
            ],
            [
                'tag' => 'Audit & recycle bin', 'resource' => null, 'method' => 'GET', 'path' => '/api/v1/_archive',
                'operationId' => 'archiveList', 'summary' => 'List archived (deleted) rows.',
                'auth' => true, 'scope' => 'archive:read', 'pathParams' => [],
                'query' => [['name' => 'resource', 'description' => 'Optional resource filter.'], ['name' => 'restored', 'description' => 'Filter by restoration state: false = still-deleted (restorable), true = already restored. Omit for all.'], self::pageParam(), self::perPageParam()],
                'body' => null, 'success' => 200, 'successKind' => 'collection', 'captureId' => false,
            ],
            [
                'tag' => 'Audit & recycle bin', 'resource' => null, 'method' => 'GET', 'path' => '/api/v1/_archive/{id}',
                'operationId' => 'archiveShow', 'summary' => 'Inspect one archived record (full payload).',
                'auth' => true, 'scope' => 'archive:read', 'pathParams' => ['id'], 'query' => [], 'body' => null,
                'success' => 200, 'successKind' => 'item', 'captureId' => false,
            ],
            [
                'tag' => 'Audit & recycle bin', 'resource' => null, 'method' => 'POST', 'path' => '/api/v1/_archive/{id}/restore',
                'operationId' => 'archiveRestore', 'summary' => 'Restore an archived record to its original table.',
                'auth' => true, 'scope' => 'archive:write', 'pathParams' => ['id'], 'query' => [], 'body' => null,
                'success' => 200, 'successKind' => 'item', 'captureId' => false,
            ],
            [
                'tag' => 'Audit & recycle bin', 'resource' => null, 'method' => 'GET', 'path' => '/api/v1/_audit',
                'operationId' => 'auditList', 'summary' => 'Read the data-mutation audit trail.',
                'auth' => true, 'scope' => 'audit:read', 'pathParams' => [],
                'query' => [['name' => 'resource', 'description' => 'Optional resource filter.'], ['name' => 'record_id', 'description' => 'Optional record id filter.'], ['name' => 'sinceId', 'description' => 'Incremental polling: return only entries after this audit id, oldest-first — poll with the last id you saw (n8n change-data-capture).'], self::pageParam(), self::perPageParam()],
                'body' => null, 'success' => 200, 'successKind' => 'collection', 'captureId' => false,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function forResource(ResourceDefinition $def): array
    {
        $tag  = ucfirst($def->slug);
        $base = '/api/v1/' . $def->slug;

        $listQuery = [self::pageParam(), self::perPageParam(),
            ['name' => 'cursor', 'description' => 'Opt into keyset pagination (stable, index-fast, ideal for n8n). Send the param empty to start, then follow meta.pagination.nextCursor until it is null. Iterates by ' . $def->primaryKey . '; combine only with sort=' . $def->primaryKey . ' / -' . $def->primaryKey . '.'],
            ['name' => 'sort', 'description' => 'Sort by one or more columns, comma-separated (e.g. -price,name); prefix "-" for descending. Allowed: ' . implode(', ', $def->sortable) . ' (and ' . $def->primaryKey . '). Any other column is rejected with 400 (not silently ignored).'],
            ['name' => 'fields', 'description' => 'Comma-separated sparse fieldset. Allowed: ' . implode(', ', $def->outputColumns()) . '.'],
            ['name' => 'filter[' . ($def->filterable[0] ?? 'col') . ']', 'description' => 'Filter. Columns: ' . implode(', ', $def->filterable) . '. Operators: ' . implode(', ', QueryParser::OPERATORS) . ' (e.g. filter[col][gte]=10). Numeric columns require numeric values (a non-numeric value is rejected with 400, never coerced); for like, % and _ match literally.'],
        ];

        $endpoints = [
            [
                'tag' => $tag, 'resource' => $def->slug, 'method' => 'GET', 'path' => $base,
                'operationId' => $def->slug . 'List', 'summary' => 'List ' . $def->slug . '.',
                'auth' => true, 'scope' => $def->slug . ':read', 'pathParams' => [], 'query' => $listQuery, 'body' => null,
                'success' => 200, 'successKind' => 'collection', 'captureId' => false,
            ],
            [
                'tag' => $tag, 'resource' => $def->slug, 'method' => 'POST', 'path' => $base,
                'operationId' => $def->slug . 'Create', 'summary' => 'Create a ' . $def->slug . ' record (send a JSON array of objects to bulk-create, all-or-nothing).',
                'auth' => true, 'scope' => $def->slug . ':write', 'pathParams' => [], 'query' => [],
                'body' => self::body($def), 'success' => 201, 'successKind' => 'item', 'captureId' => true,
                'primaryKey' => $def->primaryKey,
            ],
            [
                'tag' => $tag, 'resource' => $def->slug, 'method' => 'PATCH', 'path' => $base,
                'operationId' => $def->slug . 'BulkUpdate',
                'summary' => 'Bulk update ' . $def->slug . ': a JSON array of objects, each with its ' . $def->primaryKey
                    . ' plus fields to change (all-or-nothing, max ' . ResourceController::BULK_MAX . ').',
                'auth' => true, 'scope' => $def->slug . ':write', 'pathParams' => [], 'query' => [],
                'body' => null, 'bodyExample' => self::bulkUpdateExample($def),
                'success' => 200, 'successKind' => 'collection', 'captureId' => false,
            ],
            [
                'tag' => $tag, 'resource' => $def->slug, 'method' => 'DELETE', 'path' => $base,
                'operationId' => $def->slug . 'BulkDelete',
                'summary' => 'Bulk archival delete ' . $def->slug . ': send {"ids": [...]} (all-or-nothing, max '
                    . ResourceController::BULK_MAX . ', restorable via the recycle bin).',
                'auth' => true, 'scope' => $def->slug . ':delete', 'pathParams' => [], 'query' => [],
                'body' => null, 'bodyExample' => "{\n    \"ids\": [\"1\", \"2\"]\n}",
                'success' => 200, 'successKind' => 'meta', 'captureId' => false,
            ],
            [
                'tag' => $tag, 'resource' => $def->slug, 'method' => 'GET', 'path' => $base . '/{id}',
                'operationId' => $def->slug . 'Show', 'summary' => 'Fetch one ' . $def->slug . ' by id.',
                'auth' => true, 'scope' => $def->slug . ':read', 'pathParams' => ['id'], 'query' => [], 'body' => null,
                'success' => 200, 'successKind' => 'item', 'captureId' => false,
            ],
            [
                'tag' => $tag, 'resource' => $def->slug, 'method' => 'PATCH', 'path' => $base . '/{id}',
                'operationId' => $def->slug . 'Update', 'summary' => 'Update a ' . $def->slug . ' (applies the fields sent).',
                'auth' => true, 'scope' => $def->slug . ':write', 'pathParams' => ['id'], 'query' => [],
                'body' => self::body($def), 'success' => 200, 'successKind' => 'item', 'captureId' => false,
            ],
            [
                'tag' => $tag, 'resource' => $def->slug, 'method' => 'PUT', 'path' => $base . '/{id}',
                'operationId' => $def->slug . 'UpdatePut', 'summary' => 'Update a ' . $def->slug . ' (PUT alias of the update operation — applies the fields sent; clients that default to PUT can use it).',
                'auth' => true, 'scope' => $def->slug . ':write', 'pathParams' => ['id'], 'query' => [],
                'body' => self::body($def), 'success' => 200, 'successKind' => 'item', 'captureId' => false,
            ],
            [
                'tag' => $tag, 'resource' => $def->slug, 'method' => 'DELETE', 'path' => $base . '/{id}',
                'operationId' => $def->slug . 'Delete', 'summary' => 'Archival delete (moves the row to the recycle bin).',
                'auth' => true, 'scope' => $def->slug . ':delete', 'pathParams' => ['id'], 'query' => [], 'body' => null,
                'success' => 204, 'successKind' => 'none', 'captureId' => false,
            ],
        ];

        // Collection PUT = upsert (create-or-update by the natural key) — only for
        // resources that declare one. Ideal for n8n data-sync workflows.
        if ($def->upsertKey !== null) {
            $endpoints[] = [
                'tag' => $tag, 'resource' => $def->slug, 'method' => 'PUT', 'path' => $base,
                'operationId' => $def->slug . 'Upsert',
                'summary' => 'Upsert ' . $def->slug . ' by `' . $def->upsertKey . '` (create-or-update): send one object '
                    . 'or a JSON array; each item matched on ' . $def->upsertKey . ' is updated, others created '
                    . '(all-or-nothing, max ' . ResourceController::BULK_MAX . '). meta: {upserted, created, updated}.',
                'auth' => true, 'scope' => $def->slug . ':write', 'pathParams' => [], 'query' => [],
                'body' => self::body($def), 'success' => 200, 'successKind' => 'item', 'captureId' => false,
            ];
        }

        // Attach the resource's output schema to every endpoint so the OpenAPI can
        // document the response `data` fields (types + enums), not just the input body.
        $output = self::outputSchema($def);

        return array_map(static fn (array $e): array => $e + ['output' => $output], $endpoints);
    }

    /**
     * Response `data` schema for a resource: each output column mapped to its JSON
     * type (from the declared cast) with an `enum` where the field is constrained.
     * Mirrors what the CRUD response actually returns.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function outputSchema(ResourceDefinition $def): array
    {
        $props = [];
        foreach ($def->outputColumns() as $column) {
            $schema = match ($def->casts[$column] ?? null) {
                'int'      => ['type' => 'integer'],
                'float'    => ['type' => 'number'],
                'bool'     => ['type' => 'boolean'],
                'datetime' => ['type' => 'string', 'format' => 'date-time'],
                default    => ['type' => 'string'],
            };
            $enum = self::enumValues($def->createRules[$column] ?? '');
            if ($enum !== []) {
                $schema['enum'] = $enum;
            }
            $props[$column] = $schema;
        }

        return $props;
    }

    /**
     * @return array<string, array{type: string, required: bool, enum: list<string>, example: string|int|float}>
     */
    private static function body(ResourceDefinition $def): array
    {
        $fields = [];
        foreach ($def->fillable as $field) {
            $rule           = $def->createRules[$field] ?? '';
            $type           = self::inferType($rule);
            $enum           = self::enumValues($rule);
            $fields[$field] = [
                'type'     => $type,
                'required' => str_contains($rule, 'required'),
                'enum'     => $enum,
                // A VALID example: an allowed enum value where the field is
                // constrained, else a type-appropriate placeholder. So a human can
                // import the collection and the create/upsert request succeeds
                // instead of tripping validation (e.g. status must be active|archived).
                'example'  => self::exampleValue($field, $type, $enum),
            ];
        }

        return $fields;
    }

    /**
     * Allowed values from an `in_list[a,b,c]` rule, else empty.
     *
     * @return list<string>
     */
    private static function enumValues(string $rule): array
    {
        if (preg_match('/in_list\[([^\]]+)\]/', $rule, $m) === 1) {
            return array_values(array_filter(array_map('trim', explode(',', $m[1])), static fn (string $v): bool => $v !== ''));
        }

        return [];
    }

    /**
     * @param list<string> $enum
     */
    private static function exampleValue(string $field, string $type, array $enum): string|int|float
    {
        if ($enum !== []) {
            return $enum[0];
        }

        return match ($type) {
            'number'  => 9.99,
            'integer' => 1,
            default   => $field, // e.g. "sku" — a clearer placeholder than "string"
        };
    }

    /**
     * A one-object example for bulk update: the primary key plus each writable
     * field with a type-appropriate placeholder, wrapped in an array.
     */
    private static function bulkUpdateExample(ResourceDefinition $def): string
    {
        $object = [$def->primaryKey => '1'];
        foreach (self::body($def) as $field => $meta) {
            $object[$field] = $meta['example'];
        }

        return (string) json_encode([$object], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private static function inferType(string $rule): string
    {
        return match (true) {
            str_contains($rule, 'decimal'), str_contains($rule, 'numeric') => 'number',
            str_contains($rule, 'integer'), str_contains($rule, 'is_natural') => 'integer',
            default => 'string',
        };
    }

    /**
     * @return array{name: string, description: string}
     */
    private static function pageParam(): array
    {
        return ['name' => 'page', 'description' => 'Page number (1-based).'];
    }

    /**
     * @return array{name: string, description: string}
     */
    private static function perPageParam(): array
    {
        return ['name' => 'perPage', 'description' => 'Items per page (capped per resource).'];
    }
}

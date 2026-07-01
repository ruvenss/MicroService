<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Libraries\AuthContext;
use App\Libraries\Authorization;
use App\Libraries\QueryParser;
use App\Libraries\ResourceDefinition;
use App\Libraries\ResourceRegistry;
use App\Libraries\ResponseEnvelope;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Self-description of the API surface: lists registered resources and how each
 * may be queried. Describes only the resources the calling key can actually use
 * (least privilege — see below) and never the engine behind them.
 */
class Discovery extends BaseController
{
    public function resources(): ResponseInterface
    {
        $registry = ResourceRegistry::instance();
        $scopes   = AuthContext::scopes();
        $data     = [];

        foreach ($registry->slugs() as $slug) {
            $definition = $registry->get($slug);
            if ($definition === null) {
                continue;
            }

            // Least privilege: only advertise a resource this key can read/write/
            // delete, so a limited (or leaked) key never discovers the names and
            // schemas of resources it has no scope for.
            if (! Authorization::permitsResource($scopes, $slug)) {
                continue;
            }

            $data[] = [
                'resource'   => $slug,
                'endpoint'   => "/api/v1/{$slug}",
                'fields'     => $definition->outputColumns(),
                'writable'   => $definition->fillable,
                // Per-field input schema (type + required) so a client — e.g. an n8n
                // node — can build a request/form without guessing.
                'schema'     => $this->schemaFor($definition),
                // The primary key is always sortable AND filterable (it is in every
                // response and reachable via GET /{id}), so advertise it even when a
                // resource does not list it — n8n can sort or fetch-by-id set on it.
                'sortable'   => $this->withPrimaryKey($definition->sortable, $definition->primaryKey),
                'filterable' => $this->withPrimaryKey($definition->filterable, $definition->primaryKey),
                'operators'  => QueryParser::OPERATORS,
                // Natural key for PUT upsert (null = upsert not supported here).
                'upsertKey'  => $definition->upsertKey,
                'perPage'    => ['default' => $definition->perPageDefault, 'max' => $definition->perPageMax],
                // Max items per bulk create/update/upsert/delete request, so an n8n
                // sync workflow can chunk a large batch to fit instead of discovering
                // the limit by hitting a 422.
                'bulkMax'    => ResourceController::BULK_MAX,
            ];
        }

        return $this->response->setJSON(ResponseEnvelope::wrap($data));
    }

    /**
     * Input schema per writable field: JSON type, whether it is required on create,
     * and — for a constrained field — its allowed `enum` values. Type comes from the
     * declared output cast, else is inferred from the validation rule. The `enum`
     * (from an `in_list[...]` rule) mirrors what OpenAPI/Postman expose, so an n8n
     * node building a request from this single live call knows the valid choices
     * (e.g. status = active|archived) instead of guessing at a bare "string".
     *
     * @return array<string, array{type: string, required: bool, enum?: list<string>}>
     */
    private function schemaFor(ResourceDefinition $definition): array
    {
        $schema = [];
        foreach ($definition->fillable as $field) {
            $rule  = $definition->createRules[$field] ?? '';
            $entry = [
                'type'     => $definition->casts[$field] ?? $this->inferType($rule),
                'required' => str_contains($rule, 'required'),
            ];

            $enum = $this->enumValues($rule);
            if ($enum !== []) {
                $entry['enum'] = $enum;
            }

            $schema[$field] = $entry;
        }

        return $schema;
    }

    /**
     * A copy of the allow-list with the primary key included (at the front if it was
     * not already declared), so discovery reflects that the pk is always sortable and
     * filterable.
     *
     * @param list<string> $columns
     *
     * @return list<string>
     */
    private function withPrimaryKey(array $columns, string $primaryKey): array
    {
        return in_array($primaryKey, $columns, true) ? $columns : [$primaryKey, ...$columns];
    }

    /**
     * Allowed values from an `in_list[a,b,c]` validation rule, else empty. Same
     * derivation as the docs generator, so discovery and OpenAPI never disagree.
     *
     * @return list<string>
     */
    private function enumValues(string $rule): array
    {
        if (preg_match('/in_list\[([^\]]+)\]/', $rule, $m) === 1) {
            return array_values(array_filter(array_map('trim', explode(',', $m[1])), static fn (string $v): bool => $v !== ''));
        }

        return [];
    }

    private function inferType(string $rule): string
    {
        return match (true) {
            str_contains($rule, 'decimal'), str_contains($rule, 'numeric')    => 'float',
            str_contains($rule, 'integer'), str_contains($rule, 'is_natural') => 'int',
            default                                                           => 'string',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Libraries\QueryParser;
use App\Libraries\ResourceDefinition;
use App\Libraries\ResourceRegistry;
use App\Libraries\ResponseEnvelope;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Self-description of the API surface: lists registered resources and how each
 * may be queried. Describes only the resources already exposed via CRUD (no
 * engine disclosure). Will be gated behind an admin scope once auth lands.
 */
class Discovery extends BaseController
{
    public function resources(): ResponseInterface
    {
        $registry = ResourceRegistry::instance();
        $data     = [];

        foreach ($registry->slugs() as $slug) {
            $definition = $registry->get($slug);
            if ($definition === null) {
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
                'sortable'   => $definition->sortable,
                'filterable' => $definition->filterable,
                'operators'  => QueryParser::OPERATORS,
                // Natural key for PUT upsert (null = upsert not supported here).
                'upsertKey'  => $definition->upsertKey,
                'perPage'    => ['default' => $definition->perPageDefault, 'max' => $definition->perPageMax],
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

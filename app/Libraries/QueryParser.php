<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Parses ?filter[...] and ?fields against a resource's allow-lists.
 *
 * Security: column names and operators are validated against the definition's
 * allow-lists (never taken from raw input as SQL); the controller binds values
 * through the query builder. Unknown columns/operators/fields are rejected (the
 * caller turns a non-empty error list into a 400).
 */
final class QueryParser
{
    /** @var list<string> */
    public const OPERATORS = ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'like', 'in', 'nin'];

    /**
     * @param array<string, mixed> $get the full query-string array
     */
    public static function parse(array $get, ResourceDefinition $definition): QuerySpec
    {
        $errors = [];

        $filters = self::parseFilters($get['filter'] ?? null, $definition, $errors);
        $fields  = self::parseFields($get['fields'] ?? null, $definition, $errors);

        return new QuerySpec($filters, $fields, $errors);
    }

    /**
     * @param mixed         $raw
     * @param list<string>  $errors
     *
     * @return list<array{column: string, operator: string, value: mixed}>
     */
    private static function parseFilters($raw, ResourceDefinition $definition, array &$errors): array
    {
        if ($raw === null) {
            return [];
        }

        if (! is_array($raw)) {
            $errors[] = 'filter must be supplied as filter[column]=value.';

            return [];
        }

        $filters = [];

        foreach ($raw as $column => $spec) {
            if (! in_array($column, $definition->filterable, true)) {
                $errors[] = "Unknown or non-filterable column: {$column}.";

                continue;
            }

            if (is_array($spec)) {
                foreach ($spec as $operator => $value) {
                    if (! is_string($operator) || ! in_array($operator, self::OPERATORS, true)) {
                        $errors[] = "Unknown operator for {$column}.";

                        continue;
                    }
                    $filters[] = self::buildFilter((string) $column, $operator, $value);
                }

                continue;
            }

            $filters[] = self::buildFilter((string) $column, 'eq', $spec);
        }

        return $filters;
    }

    /**
     * @param mixed $value
     *
     * @return array{column: string, operator: string, value: mixed}
     */
    private static function buildFilter(string $column, string $operator, $value): array
    {
        if ($operator === 'in' || $operator === 'nin') {
            $value = is_array($value) ? array_values($value) : explode(',', (string) $value);
        } else {
            $value = is_array($value) ? implode(',', $value) : (string) $value;
        }

        return ['column' => $column, 'operator' => $operator, 'value' => $value];
    }

    /**
     * @param mixed        $raw
     * @param list<string> $errors
     *
     * @return list<string>|null
     */
    private static function parseFields($raw, ResourceDefinition $definition, array &$errors): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (! is_string($raw)) {
            $errors[] = 'fields must be a comma-separated list.';

            return null;
        }

        $allowed   = $definition->outputColumns();
        $requested = array_filter(array_map('trim', explode(',', $raw)), static fn (string $f): bool => $f !== '');

        $fields = [];

        foreach ($requested as $field) {
            if (! in_array($field, $allowed, true)) {
                $errors[] = "Unknown or hidden field: {$field}.";

                continue;
            }
            $fields[] = $field;
        }

        return $fields === [] ? null : array_values(array_unique($fields));
    }
}

<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Parses ?filter[...], ?fields and ?sort against a resource's allow-lists.
 *
 * Security: column names and operators are validated against the definition's
 * allow-lists (never taken from raw input as SQL); the controller binds values
 * through the query builder. Unknown columns/operators/fields/sort keys are
 * rejected (the caller turns a non-empty error list into a 400) — a request that
 * asks for something the resource does not expose fails loudly rather than
 * silently returning a different result than the caller expects.
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
        self::validateSort($get['sort'] ?? null, $definition, $errors);

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
            // The primary key is always filterable (as it is always sortable): it is
            // in every response and reachable via GET /{id}, so exposing it to filters
            // leaks nothing new and lets an n8n workflow fetch a set of records by id
            // (filter[id][in]=1,2,3) or page by key range (filter[id][gt]=N).
            if (! in_array($column, $definition->filterable, true) && $column !== $definition->primaryKey) {
                $errors[] = "Unknown or non-filterable column: {$column}.";

                continue;
            }

            if (is_array($spec)) {
                foreach ($spec as $operator => $value) {
                    if (! is_string($operator) || ! in_array($operator, self::OPERATORS, true)) {
                        $errors[] = "Unknown operator for {$column}.";

                        continue;
                    }
                    if (! self::valueTypeMatches((string) $column, $operator, $value, $definition)) {
                        $errors[] = "Filter value for {$column} must be numeric.";

                        continue;
                    }
                    $filters[] = self::buildFilter((string) $column, $operator, $value);
                }

                continue;
            }

            if (! self::valueTypeMatches((string) $column, 'eq', $spec, $definition)) {
                $errors[] = "Filter value for {$column} must be numeric.";

                continue;
            }
            $filters[] = self::buildFilter((string) $column, 'eq', $spec);
        }

        return $filters;
    }

    /**
     * For a numeric column (cast `int`/`float`), every value on a comparison /
     * equality / set operator must itself be numeric. Otherwise MySQL silently
     * coerces a non-numeric string to 0 — `filter[price][gt]=abc` becomes
     * `price > 0`, quietly matching every row — and the caller (e.g. an n8n
     * workflow with an empty/garbage variable) gets wrong results with no error.
     * `like` is exempt (a substring match, cast to text); non-numeric columns
     * accept any value. Consistent with the loud rejection of bad filter/sort/fields.
     *
     * @param mixed $value
     */
    private static function valueTypeMatches(string $column, string $operator, $value, ResourceDefinition $definition): bool
    {
        if ($operator === 'like') {
            return true;
        }

        $type = $definition->casts[$column] ?? null;
        if ($type !== 'int' && $type !== 'float') {
            return true;
        }

        $values = is_array($value)
            ? $value
            : (($operator === 'in' || $operator === 'nin') ? explode(',', (string) $value) : [$value]);

        foreach ($values as $single) {
            if (is_array($single) || ! is_numeric($single)) {
                return false;
            }
        }

        return $values !== [];
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

    /**
     * Reject any `?sort=col,-col2,…` token whose column is not sortable, so an
     * unknown or non-exposed sort key fails with a 400 instead of being silently
     * dropped (which would return default-ordered rows the caller didn't ask for —
     * a subtle correctness trap for an n8n workflow that relies on the order). The
     * primary key is always a valid sort target: it is the guaranteed tiebreaker
     * and the key cursor pagination iterates by. Consistent with filter/fields.
     *
     * @param mixed        $raw
     * @param list<string> $errors
     */
    private static function validateSort($raw, ResourceDefinition $definition, array &$errors): void
    {
        if ($raw === null || $raw === '') {
            return;
        }

        if (! is_string($raw)) {
            $errors[] = 'sort must be a comma-separated list of columns.';

            return;
        }

        $tokens = array_filter(array_map('trim', explode(',', $raw)), static fn (string $t): bool => $t !== '');

        foreach ($tokens as $token) {
            $column = ltrim($token, '-+');
            if ($column === '') {
                continue;
            }
            if (! in_array($column, $definition->sortable, true) && $column !== $definition->primaryKey) {
                $errors[] = "Unknown or non-sortable column: {$column}.";
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Immutable description of one resource's API surface, built from the registry
 * (Config\Resources). The generic engine reads this — there is no per-entity
 * PHP for standard CRUD.
 */
final class ResourceDefinition
{
    /**
     * @param list<string>          $fillable    columns a client may write
     * @param list<string>          $hidden      columns never serialized
     * @param array<string, string> $createRules validation rules for create
     * @param array<string, string> $updateRules validation rules for update
     * @param list<string>          $sortable    columns allowed in ?sort
     * @param list<string>          $filterable  columns allowed in ?filter
     * @param array<string, string> $casts       output column => int|float|bool|string
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $table,
        public readonly string $primaryKey,
        public readonly array $fillable,
        public readonly array $hidden,
        public readonly array $createRules,
        public readonly array $updateRules,
        public readonly array $sortable,
        public readonly array $filterable,
        public readonly string $defaultSort,
        public readonly int $perPageDefault,
        public readonly int $perPageMax,
        public readonly bool $timestamps,
        public readonly array $casts = [],
        public readonly ?string $upsertKey = null,
    ) {
    }

    /**
     * Columns a client may receive (and therefore request via ?fields):
     * primary key + writable fields + managed timestamps, minus hidden.
     *
     * @return list<string>
     */
    public function outputColumns(): array
    {
        $columns = [$this->primaryKey, ...$this->fillable];
        if ($this->timestamps) {
            $columns[] = 'created_at';
            $columns[] = 'updated_at';
        }

        return array_values(array_diff(array_unique($columns), $this->hidden));
    }

    /**
     * Columns the create rules mark `is_unique` — i.e. backed by a unique index. Used
     * to reject an intra-batch duplicate (bulk create), and to pre-check a restore
     * against a value that was reused after the delete, before hitting the index.
     *
     * @return list<string>
     */
    public function uniqueColumns(): array
    {
        $columns = [];
        foreach ($this->createRules as $column => $rule) {
            if (str_contains($rule, 'is_unique')) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /**
     * @param array<string, mixed> $def
     */
    public static function fromArray(string $slug, array $def): self
    {
        $primaryKey = $def['primaryKey'] ?? 'id';

        return new self(
            slug: $slug,
            table: (string) $def['table'],
            primaryKey: $primaryKey,
            fillable: $def['fillable'] ?? [],
            hidden: $def['hidden'] ?? [],
            createRules: $def['rules']['create'] ?? [],
            updateRules: $def['rules']['update'] ?? [],
            sortable: $def['sortable'] ?? [],
            filterable: $def['filterable'] ?? [],
            defaultSort: $def['defaultSort'] ?? $primaryKey,
            perPageDefault: (int) ($def['perPage']['default'] ?? 25),
            perPageMax: (int) ($def['perPage']['max'] ?? 100),
            timestamps: $def['timestamps'] ?? true,
            casts: $def['casts'] ?? [],
            upsertKey: isset($def['upsertKey']) ? (string) $def['upsertKey'] : null,
        );
    }

    /**
     * Cast a raw DB row (MySQLi returns every column as a string) to the declared
     * JSON types, so every surface that emits this resource's records — the CRUD
     * response and the `_audit` change feed's before/after snapshots — presents
     * int/float/bool and ISO-8601 `Z` timestamps identically, and an n8n workflow
     * parses them with one rule. Absent/null/undeclared fields pass through.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public function castRow(array $row): array
    {
        foreach ($this->casts as $field => $type) {
            if (! array_key_exists($field, $row) || $row[$field] === null) {
                continue;
            }
            $row[$field] = match ($type) {
                'int'      => (int) $row[$field],
                'float'    => (float) $row[$field],
                'bool'     => (bool) $row[$field],
                'string'   => (string) $row[$field],
                'datetime' => Timestamp::iso((string) $row[$field]),
                default    => $row[$field],
            };
        }

        return $row;
    }
}

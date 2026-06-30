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
        public readonly string $defaultSort,
        public readonly int $perPageDefault,
        public readonly int $perPageMax,
        public readonly bool $timestamps,
    ) {
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
            defaultSort: $def['defaultSort'] ?? $primaryKey,
            perPageDefault: (int) ($def['perPage']['default'] ?? 25),
            perPageMax: (int) ($def['perPage']['max'] ?? 100),
            timestamps: $def['timestamps'] ?? true,
        );
    }
}

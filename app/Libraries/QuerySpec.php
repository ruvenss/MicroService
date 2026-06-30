<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Parsed, validated query intent for a list/show request.
 */
final class QuerySpec
{
    /**
     * @param list<array{column: string, operator: string, value: mixed}> $filters
     * @param list<string>|null                                           $fields  null = all columns
     * @param list<string>                                                $errors  empty = valid
     */
    public function __construct(
        public readonly array $filters,
        public readonly ?array $fields,
        public readonly array $errors,
    ) {
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}

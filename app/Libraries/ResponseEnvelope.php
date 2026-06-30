<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Builds the standard success envelope: { "data": ..., "meta": ... }.
 *
 * `meta` is omitted entirely when empty so single-resource responses stay lean.
 * Collections carry pagination under `meta.pagination`.
 */
final class ResponseEnvelope
{
    /**
     * Wrap a single resource (or any payload).
     *
     * @param mixed                $data
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    public static function wrap($data, array $meta = []): array
    {
        $envelope = ['data' => $data];

        if ($meta !== []) {
            $envelope['meta'] = $meta;
        }

        return $envelope;
    }

    /**
     * Wrap a collection with pagination metadata.
     *
     * @param list<mixed> $items
     *
     * @return array<string, mixed>
     */
    public static function collection(array $items, int $page, int $perPage, int $total): array
    {
        return [
            'data' => $items,
            'meta' => [
                'pagination' => [
                    'page'       => $page,
                    'perPage'    => $perPage,
                    'total'      => $total,
                    'totalPages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
                ],
            ],
        ];
    }
}

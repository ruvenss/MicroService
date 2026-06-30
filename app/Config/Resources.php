<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Resource registry — the input to the generic CRUD engine.
 *
 * Each entry declares one table's API surface (table, writable fields, hidden
 * fields, validation, sortable columns, pagination). Adding a resource is a
 * config change, not new PHP. Plugins will contribute entries here once the
 * plugin system lands (see docs/ARCHITECTURE.md §15).
 */
class Resources extends BaseConfig
{
    /**
     * @var array<string, array<string, mixed>>
     */
    public array $resources = [
        'products' => [
            'table'      => 'products',
            'primaryKey' => 'id',
            'fillable'   => ['sku', 'name', 'price', 'status'],
            'hidden'     => [],
            'rules'      => [
                'create' => [
                    'sku'    => 'required|max_length[64]|is_unique[products.sku]',
                    'name'   => 'required|max_length[200]',
                    'price'  => 'required|decimal',
                    'status' => 'permit_empty|in_list[active,archived]',
                ],
                'update' => [
                    'sku'    => 'permit_empty|max_length[64]',
                    'name'   => 'permit_empty|max_length[200]',
                    'price'  => 'permit_empty|decimal',
                    'status' => 'permit_empty|in_list[active,archived]',
                ],
            ],
            'sortable'    => ['sku', 'name', 'price', 'created_at'],
            'filterable'  => ['sku', 'status', 'price'],
            'defaultSort' => '-created_at',
            'perPage'     => ['default' => 25, 'max' => 100],
            'timestamps'  => true,
        ],
    ];
}

<?php

declare(strict_types=1);

namespace Plugins\Sample\Catalog;

use App\Core\Plugin\PluginInterface;
use App\Core\Plugin\PluginManager;

/**
 * Sample plugin. Contributes the `products` resource to the generic CRUD engine
 * — demonstrating that a fully-featured REST resource (CRUD, filtering, auth
 * scopes, audit, archival delete, generated docs) is delivered from plugins/
 * with no change to the sealed core (app/).
 */
final class Plugin implements PluginInterface
{
    public function register(PluginManager $manager): void
    {
        $manager->registerResource('products', [
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
            // Typed JSON output (MySQLi returns strings) — friendlier for n8n.
            'casts'       => ['id' => 'int', 'price' => 'float'],
        ]);
    }

    public function boot(): void
    {
        // No cross-plugin wiring needed for this sample.
    }
}

<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Index the sample resource's exposed filter/sort columns so list queries are
 * index-backed instead of full-table scans / filesorts. `sku` is already UNIQUE and
 * `status` already indexed; this covers the rest declared filterable/sortable in the
 * Sample\Catalog plugin: `price` (filter + sort), `name` (sort), and a composite on
 * `(created_at, id)` for the default `-created_at` sort with its id tiebreaker.
 *
 * This is also the convention plugin authors should follow: every column exposed as
 * `filterable`/`sortable` wants a supporting index, or the generic engine scans.
 */
final class AddProductsQueryIndexes extends Migration
{
    public function up(): void
    {
        $this->forge->addKey(['created_at', 'id'], false, false, 'products_created_at_id');
        $this->forge->addKey('price', false, false, 'products_price');
        $this->forge->addKey('name', false, false, 'products_name');
        $this->forge->processIndexes('products');
    }

    public function down(): void
    {
        $this->forge->dropKey('products', 'products_created_at_id', false);
        $this->forge->dropKey('products', 'products_price', false);
        $this->forge->dropKey('products', 'products_name', false);
    }
}

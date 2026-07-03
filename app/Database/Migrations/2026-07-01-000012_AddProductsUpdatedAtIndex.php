<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * `updated_at` is now exposed as filterable/sortable (date-range incremental sync,
 * e.g. filter[updated_at][gte]=<last-run>&sort=updated_at). Index (updated_at, id)
 * — matching the tiebreaker — so that query is an index range scan, not a filesort.
 * (created_at already has (created_at, id) from AddProductsQueryIndexes.)
 */
final class AddProductsUpdatedAtIndex extends Migration
{
    public function up(): void
    {
        $this->forge->addKey(['updated_at', 'id'], false, false, 'products_updated_at_id');
        $this->forge->processIndexes('products');
    }

    public function down(): void
    {
        $this->forge->dropKey('products', 'products_updated_at_id', false);
    }
}

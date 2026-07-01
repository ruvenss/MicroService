<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Every column the Sample\Catalog plugin exposes as filterable/sortable must be
 * backed by an index (leftmost column for composites), so the generic CRUD engine
 * never falls back to a full-table scan / filesort on a real (large) table.
 *
 * @internal
 */
final class ProductsIndexTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $refresh   = true;

    public function testExposedFilterAndSortColumnsAreIndexed(): void
    {
        $indexed = [];
        foreach (db_connect()->getIndexData('products') as $index) {
            // The leftmost indexed column is what a filter/sort can use.
            if (isset($index->fields[0])) {
                $indexed[$index->fields[0]] = true;
            }
        }

        // filterable: sku, status, price — sortable: sku, name, price, created_at.
        foreach (['sku', 'status', 'price', 'name', 'created_at'] as $column) {
            $this->assertArrayHasKey($column, $indexed, "exposed column '{$column}' must be index-backed");
        }
    }
}

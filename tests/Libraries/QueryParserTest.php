<?php

declare(strict_types=1);

use App\Libraries\QueryParser;
use App\Libraries\ResourceDefinition;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class QueryParserTest extends CIUnitTestCase
{
    private function definition(): ResourceDefinition
    {
        return ResourceDefinition::fromArray('products', [
            'table'      => 'products',
            'primaryKey' => 'id',
            'fillable'   => ['sku', 'name', 'price', 'status'],
            'filterable' => ['sku', 'status', 'price'],
            'sortable'   => ['sku', 'name'],
            'timestamps' => true,
            'casts'      => ['id' => 'int', 'price' => 'float'],
        ]);
    }

    public function testParsesSimpleEqualityFilter(): void
    {
        $spec = QueryParser::parse(['filter' => ['status' => 'active']], $this->definition());

        $this->assertTrue($spec->isValid());
        $this->assertSame(
            [['column' => 'status', 'operator' => 'eq', 'value' => 'active']],
            $spec->filters,
        );
    }

    public function testParsesOperatorFilter(): void
    {
        $spec = QueryParser::parse(['filter' => ['price' => ['gt' => '10']]], $this->definition());

        $this->assertTrue($spec->isValid());
        $this->assertSame('gt', $spec->filters[0]['operator']);
        $this->assertSame('10', $spec->filters[0]['value']);
    }

    public function testInOperatorSplitsCsv(): void
    {
        $spec = QueryParser::parse(['filter' => ['status' => ['in' => 'active,archived']]], $this->definition());

        $this->assertSame(['active', 'archived'], $spec->filters[0]['value']);
    }

    public function testRejectsUnknownColumn(): void
    {
        $spec = QueryParser::parse(['filter' => ['bogus' => 'x']], $this->definition());

        $this->assertFalse($spec->isValid());
        $this->assertStringContainsString('bogus', $spec->errors[0]);
    }

    public function testPrimaryKeyIsAlwaysFilterable(): void
    {
        // id is not in `filterable`, but the primary key is always allowed (as it is
        // always sortable) so a client can fetch a set of records by id.
        $spec = QueryParser::parse(['filter' => ['id' => ['in' => '1,2,3']]], $this->definition());

        $this->assertTrue($spec->isValid());
        $this->assertSame(['1', '2', '3'], $spec->filters[0]['value']);
    }

    public function testRejectsUnknownOperator(): void
    {
        $spec = QueryParser::parse(['filter' => ['price' => ['bad' => '1']]], $this->definition());

        $this->assertFalse($spec->isValid());
    }

    public function testParsesSparseFields(): void
    {
        $spec = QueryParser::parse(['fields' => 'id,name'], $this->definition());

        $this->assertTrue($spec->isValid());
        $this->assertSame(['id', 'name'], $spec->fields);
    }

    public function testRejectsUnknownOrHiddenField(): void
    {
        $spec = QueryParser::parse(['fields' => 'id,secret'], $this->definition());

        $this->assertFalse($spec->isValid());
    }

    public function testEmptyQueryIsValidAndUnfiltered(): void
    {
        $spec = QueryParser::parse([], $this->definition());

        $this->assertTrue($spec->isValid());
        $this->assertSame([], $spec->filters);
        $this->assertNull($spec->fields);
    }

    public function testAcceptsSortableColumnsIncludingDescendingAndPrimaryKey(): void
    {
        // Allow-listed columns, a `-` prefix, and the primary key (always sortable,
        // even though it is not in `sortable`) all validate.
        foreach (['name', '-sku', 'sku,-name', 'id', '-id'] as $sort) {
            $spec = QueryParser::parse(['sort' => $sort], $this->definition());
            $this->assertTrue($spec->isValid(), "sort={$sort} should be valid");
        }
    }

    public function testRejectsNonNumericValueOnNumericColumn(): void
    {
        // price is cast float → `price > abc` would silently become `price > 0`
        // (matches everything) in MySQL, so a non-numeric value must be rejected.
        foreach (['gt', 'gte', 'lt', 'lte', 'eq', 'ne'] as $op) {
            $spec = QueryParser::parse(['filter' => ['price' => [$op => 'abc']]], $this->definition());
            $this->assertFalse($spec->isValid(), "price {$op} abc should be invalid");
            $this->assertStringContainsString('numeric', $spec->errors[0]);
        }

        // Bare-equality shorthand and set membership are validated too.
        $this->assertFalse(QueryParser::parse(['filter' => ['price' => 'abc']], $this->definition())->isValid());
        $this->assertFalse(QueryParser::parse(['filter' => ['price' => ['in' => '1,x,3']]], $this->definition())->isValid());
    }

    public function testAcceptsNumericValuesAndAnyValueOnStringColumns(): void
    {
        $this->assertTrue(QueryParser::parse(['filter' => ['price' => ['gte' => '10.5']]], $this->definition())->isValid());
        $this->assertTrue(QueryParser::parse(['filter' => ['price' => ['in' => '1,2,3']]], $this->definition())->isValid());
        $this->assertTrue(QueryParser::parse(['filter' => ['price' => '-3']], $this->definition())->isValid());
        // A string column accepts any value, including one that looks non-numeric.
        $this->assertTrue(QueryParser::parse(['filter' => ['sku' => ['like' => 'abc']]], $this->definition())->isValid());
        $this->assertTrue(QueryParser::parse(['filter' => ['status' => 'active']], $this->definition())->isValid());
    }

    public function testRejectsNonSortableColumn(): void
    {
        // `price` is filterable but NOT sortable → rejected, like an unknown column.
        $spec = QueryParser::parse(['sort' => 'price'], $this->definition());
        $this->assertFalse($spec->isValid());
        $this->assertStringContainsString('non-sortable', $spec->errors[0]);

        $spec = QueryParser::parse(['sort' => 'name,bogus'], $this->definition());
        $this->assertFalse($spec->isValid());
        $this->assertStringContainsString('bogus', $spec->errors[0]);
    }
}

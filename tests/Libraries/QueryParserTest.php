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
}

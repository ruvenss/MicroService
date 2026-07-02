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
            'filterable' => ['sku', 'status', 'price', 'created_at'],
            'sortable'   => ['sku', 'name'],
            'timestamps' => true,
            'casts'      => ['id' => 'int', 'price' => 'float', 'created_at' => 'datetime'],
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

    public function testValidatesDatetimeColumnValues(): void
    {
        // A datetime column rejects an unparseable date (else MySQL coerces it to NULL
        // and silently returns nothing), and accepts real date/datetime strings.
        $bad = QueryParser::parse(['filter' => ['created_at' => ['gte' => 'not-a-date']]], $this->definition());
        $this->assertFalse($bad->isValid());
        $this->assertStringContainsString('valid date', $bad->errors[0]);

        foreach (['2026-01-01', '2026-07-01T10:19:30Z', '2026-07-01 10:19:30'] as $date) {
            $spec = QueryParser::parse(['filter' => ['created_at' => ['gte' => $date]]], $this->definition());
            $this->assertTrue($spec->isValid(), "created_at gte {$date} should be valid");
        }
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

    public function testDatetimeFilterIsNormalisedToNaiveUtc(): void
    {
        // A datetime value is normalised to naive UTC `Y-m-d H:i:s`, so the DB
        // comparison is correct on any MySQL 8 regardless of the server's session
        // time_zone or version — not delegated to MySQL's offset parsing (8.0.19+).
        // This is the n8n incremental-sync path: filter[updated_at][gte]=<prior ISO>.
        $cases = [
            '2026-07-02T04:16:17.000Z'  => '2026-07-02 04:16:17', // Zulu + fractional seconds
            '2026-07-02T04:16:17Z'      => '2026-07-02 04:16:17', // Zulu
            '2026-07-02T06:16:17+02:00' => '2026-07-02 04:16:17', // numeric offset → converted to UTC
            '2026-07-02 04:16:17'       => '2026-07-02 04:16:17', // already naive → read as UTC
            '2026-07-02'                => '2026-07-02 00:00:00', // bare date
        ];

        foreach ($cases as $input => $expected) {
            $spec = QueryParser::parse(['filter' => ['created_at' => ['gte' => $input]]], $this->definition());
            $this->assertTrue($spec->isValid(), "should accept {$input}");
            $this->assertSame($expected, $spec->filters[0]['value'], "normalise {$input}");
        }
    }

    public function testDatetimeInOperatorNormalisesEachValue(): void
    {
        $spec = QueryParser::parse(
            ['filter' => ['created_at' => ['in' => '2026-07-02T00:00:00Z,2026-07-03T02:00:00+02:00']]],
            $this->definition(),
        );

        $this->assertTrue($spec->isValid());
        $this->assertSame(['2026-07-02 00:00:00', '2026-07-03 00:00:00'], $spec->filters[0]['value']);
    }

    public function testDatetimeLikeKeepsTheRawLiteral(): void
    {
        // `like` is a substring match on the textual form (e.g. group a day/month),
        // so it must NOT be normalised into a full timestamp.
        $spec = QueryParser::parse(['filter' => ['created_at' => ['like' => '2026-07']]], $this->definition());

        $this->assertTrue($spec->isValid());
        $this->assertSame('2026-07', $spec->filters[0]['value']);
    }

    public function testSparseFieldsetCannotSelectAHiddenColumn(): void
    {
        // Security: ?fields is allow-listed against outputColumns(), which excludes
        // `hidden`. So a client can NEVER use ?fields to exfiltrate a hidden/sensitive
        // column (e.g. a token or internal note) — the field is rejected, not silently
        // returned. The sample `products` has no hidden columns, so exercise a resource
        // that does.
        $def = ResourceDefinition::fromArray('widgets', [
            'table'      => 'widgets',
            'primaryKey' => 'id',
            'fillable'   => ['name', 'secret'],
            'hidden'     => ['secret'],
            'filterable' => ['name'],
            'sortable'   => ['name'],
            'timestamps' => true,
            'casts'      => ['id' => 'int'],
        ]);

        // The hidden column is not even in the exposable set.
        $this->assertNotContains('secret', $def->outputColumns());

        // Asking for it via ?fields fails loudly (400-worthy), like any unknown column.
        $bad = QueryParser::parse(['fields' => 'id,secret'], $def);
        $this->assertFalse($bad->isValid());
        $this->assertStringContainsStringIgnoringCase('hidden', $bad->errors[0]);
        $this->assertStringContainsString('secret', $bad->errors[0]);

        // A visible column selects cleanly.
        $ok = QueryParser::parse(['fields' => 'id,name'], $def);
        $this->assertTrue($ok->isValid());
        $this->assertSame(['id', 'name'], $ok->fields);
    }
}

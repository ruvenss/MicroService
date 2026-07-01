<?php

declare(strict_types=1);

use Tests\Support\FeatureTestCase;

/**
 * End-to-end filtering and sparse fieldsets against the real test database.
 *
 * @internal
 */
final class QueryFilterTest extends FeatureTestCase
{
    /** @var array<string, string> */
    private array $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auth = $this->authHeaders(['products:*']);
        $this->seedProduct('cheap', 'active', '5.00');
        $this->seedProduct('pricey', 'archived', '50.00');
    }

    private function seedProduct(string $sku, string $status, string $price): void
    {
        $this->withHeaders($this->auth)->withBodyFormat('json')->post('api/v1/products', [
            'sku' => $sku, 'name' => ucfirst($sku), 'price' => $price, 'status' => $status,
        ]);
    }

    private function list(string $query): array
    {
        $body = (string) $this->withHeaders($this->auth)->get('api/v1/products?' . $query)->response()->getBody();

        return json_decode($body, true);
    }

    public function testFilterByStatus(): void
    {
        $json = $this->list('filter[status]=active');

        $this->assertCount(1, $json['data']);
        $this->assertSame('cheap', $json['data'][0]['sku']);
    }

    public function testNonNumericFilterOnNumericColumnIsRejectedNotCoercedToEverything(): void
    {
        // `price > abc` would coerce to `price > 0` in MySQL and silently return the
        // whole table; a numeric column must reject a non-numeric value with 400,
        // like an unknown column/sort — no silent wrong result for an n8n workflow.
        $result = $this->withHeaders($this->auth)->get('api/v1/products?filter[price][gt]=abc');
        $result->assertStatus(400);
        $this->assertStringContainsString('numeric', (string) $result->response()->getBody());

        // A valid numeric filter still works.
        $this->withHeaders($this->auth)->get('api/v1/products?filter[price][gt]=10')->assertStatus(200);
    }

    public function testLikeTreatsCallerWildcardsAsLiteralNotWildcard(): void
    {
        // A caller's `%` / `_` must match literally, never act as a SQL LIKE wildcard.
        // Without escaping, `like=AA-%-BB` would also match `AA-XX-BB` (a match-anything
        // scan), so a `%` search would silently over-match and could force full-table
        // scans on the external DB. Seed a literal-`%` sku next to a decoy it must NOT catch.
        $this->seedProduct('AA-%-BB', 'active', '1.00');
        $this->seedProduct('AA-XX-BB', 'active', '1.00');

        $rows = $this->list('filter[sku][like]=AA-%25-BB&perPage=100')['data'];
        $this->assertCount(1, $rows);
        $this->assertSame('AA-%-BB', $rows[0]['sku']);

        // A bare `%` must not become a match-everything wildcard: it matches only skus
        // that literally contain a percent — here just the one seeded above.
        $skus = array_column($this->list('filter[sku][like]=%25&perPage=100')['data'], 'sku');
        $this->assertSame(['AA-%-BB'], $skus);
    }

    public function testFilterByPriceGreaterThan(): void
    {
        $json = $this->list('filter[price][gt]=10');

        $this->assertCount(1, $json['data']);
        $this->assertSame('pricey', $json['data'][0]['sku']);
    }

    public function testExplicitEqMatchesShorthand(): void
    {
        $skus = array_column($this->list('filter[status][eq]=active')['data'], 'sku');
        $this->assertContains('cheap', $skus);
        $this->assertNotContains('pricey', $skus);
    }

    public function testNeExcludesValue(): void
    {
        $skus = array_column($this->list('filter[status][ne]=archived')['data'], 'sku');
        $this->assertContains('cheap', $skus);
        $this->assertNotContains('pricey', $skus);
    }

    public function testGteLteRange(): void
    {
        $this->seedProduct('mid', 'active', '10.00');
        $skus = array_column($this->list('filter[price][gte]=6&filter[price][lte]=20&perPage=100')['data'], 'sku');
        $this->assertContains('mid', $skus);      // 10 ∈ [6,20]
        $this->assertNotContains('cheap', $skus); // 5 < 6
        $this->assertNotContains('pricey', $skus); // 50 > 20
    }

    public function testLtOperator(): void
    {
        $skus = array_column($this->list('filter[price][lt]=10&perPage=100')['data'], 'sku');
        $this->assertContains('cheap', $skus);
        $this->assertNotContains('pricey', $skus);
    }

    public function testLikeOperator(): void
    {
        // 'ric' appears in 'pricey' but not 'cheap'.
        $this->assertSame(['pricey'], array_column($this->list('filter[sku][like]=ric')['data'], 'sku'));
    }

    public function testInOperator(): void
    {
        $this->seedProduct('mid', 'active', '10.00');
        $skus = array_column($this->list('filter[sku][in]=cheap,mid&perPage=100')['data'], 'sku');
        sort($skus);
        $this->assertSame(['cheap', 'mid'], $skus);
    }

    public function testNinOperatorExcludesTheSet(): void
    {
        $this->seedProduct('mid', 'active', '10.00');
        $skus = array_column($this->list('filter[sku][nin]=pricey,mid&perPage=100')['data'], 'sku');
        $this->assertContains('cheap', $skus);
        $this->assertNotContains('pricey', $skus);
        $this->assertNotContains('mid', $skus);
    }

    public function testSparseFieldsLimitColumns(): void
    {
        $json = $this->list('fields=id,sku&perPage=1');

        $this->assertSame(['id', 'sku'], array_keys($json['data'][0]));
    }

    public function testInvalidFilterColumnReturns400(): void
    {
        $this->withHeaders($this->auth)->get('api/v1/products?filter[bogus]=x')->assertStatus(400);
    }

    public function testInvalidFieldReturns400(): void
    {
        $this->withHeaders($this->auth)->get('api/v1/products?fields=secret')->assertStatus(400);
    }

    private function seedNamed(string $sku, string $name, string $price): void
    {
        $this->withHeaders($this->auth)->withBodyFormat('json')->post('api/v1/products', [
            'sku' => $sku, 'name' => $name, 'price' => $price, 'status' => 'active',
        ]);
    }

    public function testSortsByMultipleColumns(): void
    {
        $this->seedNamed('t1', 'Bravo', '10.00');
        $this->seedNamed('t2', 'Alpha', '10.00');
        $this->seedNamed('t3', 'Zulu', '5.00');

        // price ascending, ties broken by name ascending.
        $skus = array_column($this->list('sort=price,name&perPage=100')['data'], 'sku');
        $this->assertLessThan(array_search('t2', $skus, true), array_search('t3', $skus, true)); // price 5 before price 10
        $this->assertLessThan(array_search('t1', $skus, true), array_search('t2', $skus, true)); // Alpha before Bravo at price 10

        // Reverse the primary column; secondary still ascending.
        $skus = array_column($this->list('sort=-price,name&perPage=100')['data'], 'sku');
        $this->assertLessThan(array_search('t3', $skus, true), array_search('t2', $skus, true)); // price 10 before price 5
        $this->assertLessThan(array_search('t1', $skus, true), array_search('t2', $skus, true)); // Alpha before Bravo
    }

    public function testUnknownSortColumnIsRejectedNotSilentlyDropped(): void
    {
        // A non-sortable column must fail loudly with 400 (like ?filter and ?fields),
        // never be silently dropped — otherwise the caller would get default-ordered
        // rows without knowing their requested sort was ignored (an n8n order trap).
        // The valid token in the same list does not rescue the request.
        $result = $this->withHeaders($this->auth)->get('api/v1/products?sort=bogus,-price&perPage=100');
        $result->assertStatus(400);
        $this->assertStringContainsString('non-sortable', (string) $result->response()->getBody());
    }

    public function testPrimaryKeyIsAlwaysAcceptedAsSortTarget(): void
    {
        // The primary key is a valid sort target even when not listed in `sortable`
        // (it is the guaranteed tiebreaker and the cursor iteration key).
        $this->seedNamed('pk1', 'N', '1.00');
        $this->seedNamed('pk2', 'N', '1.00');

        $ids = array_column($this->list('sort=-id&perPage=100')['data'], 'id');
        $this->assertSame($ids, array_values($ids));                 // request succeeded (200, data present)
        $this->assertGreaterThan($ids[1], $ids[0]);                  // -id → descending
    }

    public function testTiesAreBrokenByPrimaryKeyForStablePagination(): void
    {
        // Several rows share the same price → the sort column alone is not a total
        // order. The implicit id tiebreaker must make the order deterministic, so
        // paging never skips or duplicates a row.
        $ids = [];
        foreach (['a', 'b', 'c', 'd'] as $s) {
            $body = json_decode((string) $this->withHeaders($this->auth)->withBodyFormat('json')
                ->post('api/v1/products', ['sku' => "tie-{$s}", 'name' => 'N', 'price' => '7.00'])
                ->response()->getBody(), true);
            $ids[] = (int) $body['data']['id'];
        }

        // Filter to just our tied rows; sort by the non-unique price.
        $ordered = array_map(
            static fn (array $r): int => (int) $r['id'],
            $this->list('filter[price][eq]=7.00&sort=price&perPage=100')['data'],
        );
        $mine = array_values(array_intersect($ordered, $ids));
        $this->assertSame($ids, $mine); // ties resolved by id ascending (insertion order)

        // Page through one at a time: every row seen exactly once, no dupes.
        $seen = [];
        for ($page = 1; $page <= 4; $page++) {
            $row    = $this->list("filter[price][eq]=7.00&sort=price&perPage=1&page={$page}")['data'][0];
            $seen[] = (int) $row['id'];
        }
        $this->assertSame($ids, $seen);
    }
}

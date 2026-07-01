<?php

declare(strict_types=1);

use Tests\Support\FeatureTestCase;

/**
 * Opt-in keyset (cursor) pagination: `?cursor=` iterates by primary key with
 * WHERE pk > last (no OFFSET/COUNT), so paging is stable and index-fast — the
 * shape n8n's cursor pagination consumes.
 *
 * @internal
 */
final class CursorPaginationTest extends FeatureTestCase
{
    /** @var array<string, string> */
    private array $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auth = $this->authHeaders(['products:*']);
    }

    /** @return list<int> the created ids, in insertion order */
    private function seedProducts(int $count): array
    {
        $items = [];
        for ($i = 1; $i <= $count; $i++) {
            $items[] = ['sku' => "CUR-{$i}", 'name' => "Name {$i}", 'price' => '1.00'];
        }
        $json = json_decode(
            (string) $this->withHeaders($this->auth)->withBodyFormat('json')->post('api/v1/products', $items)->response()->getBody(),
            true,
        );

        return array_map(static fn (array $row): int => (int) $row['id'], $json['data']);
    }

    /** @return array{data: list<array<string,mixed>>, meta: array<string,mixed>} */
    private function page(string $query): array
    {
        return json_decode((string) $this->withHeaders($this->auth)->get('api/v1/products?' . $query)->response()->getBody(), true);
    }

    public function testWalksEveryRowOnceInOrder(): void
    {
        $ids = $this->seedProducts(5);

        $seen   = [];
        $cursor = ''; // empty cursor starts keyset pagination
        $pages  = 0;
        do {
            $json = $this->page('cursor=' . rawurlencode($cursor) . '&perPage=2');
            foreach ($json['data'] as $row) {
                $seen[] = (int) $row['id'];
            }
            $this->assertLessThanOrEqual(2, count($json['data']));
            $cursor = (string) ($json['meta']['pagination']['nextCursor'] ?? '');
            $this->assertLessThan(10, ++$pages, 'cursor pagination did not terminate');
        } while ($json['meta']['pagination']['hasMore'] === true);

        $this->assertSame($ids, $seen);                         // every row, once, in id order
        $this->assertFalse($json['meta']['pagination']['hasMore']);
        $this->assertNull($json['meta']['pagination']['nextCursor']);
        $this->assertArrayNotHasKey('total', $json['meta']['pagination']); // no COUNT in keyset mode
    }

    public function testDescendingByPrimaryKey(): void
    {
        $ids = $this->seedProducts(3);

        $json = $this->page('cursor=&sort=-id&perPage=10');
        $got  = array_map(static fn (array $r): int => (int) $r['id'], $json['data']);

        $this->assertSame(array_reverse($ids), $got);
    }

    public function testSparseFieldsStillPaginate(): void
    {
        $this->seedProducts(3);

        $json = $this->page('cursor=&fields=sku&perPage=2');
        $this->assertCount(2, $json['data']);
        $this->assertArrayHasKey('sku', $json['data'][0]);
        $this->assertArrayNotHasKey('id', $json['data'][0]); // id was added only to build the cursor, then stripped
        $this->assertNotNull($json['meta']['pagination']['nextCursor']);
    }

    public function testInvalidCursorIsRejected(): void
    {
        $this->seedProducts(1);
        $this->withHeaders($this->auth)->get('api/v1/products?cursor=not-a-real-cursor')->assertStatus(400);
    }

    public function testNonPrimaryKeySortIsRejected(): void
    {
        $this->seedProducts(1);
        $this->withHeaders($this->auth)->get('api/v1/products?cursor=&sort=name')->assertStatus(400);
    }

    public function testOffsetPaginationStillDefault(): void
    {
        $this->seedProducts(2);

        $json = $this->page('perPage=10'); // no cursor param → classic offset envelope
        $this->assertArrayHasKey('total', $json['meta']['pagination']);
        $this->assertArrayHasKey('page', $json['meta']['pagination']);
        $this->assertArrayNotHasKey('nextCursor', $json['meta']['pagination']);
    }
}

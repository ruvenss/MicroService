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

    public function testHugeFilterDoesNotProduceAnOversizedLinkHeader(): void
    {
        // The Link header echoes the whole query in every rel. A pathological query — e.g.
        // hundreds of filter[status][in][] values — would push it past the web server's
        // ~8 KiB response-header limit and crash the response with an empty 500 (only
        // visible behind Apache). The header is dropped above a safe size instead.
        $this->seedProducts(1);

        $in = [];
        for ($i = 0; $i < 300; $i++) {
            $in['filter']['status']['in'][] = 'x' . $i;
        }
        $result = $this->withHeaders($this->auth)->get('api/v1/products?' . http_build_query($in));

        $result->assertStatus(200);
        // No Link header, or a small one — never one that would blow the server limit.
        $this->assertLessThan(6001, strlen($result->response()->getHeaderLine('Link')));

        // A normal request still carries the Link header (the cap only trips on huge
        // queries) — rel="first" is always present on an offset list.
        $normal = $this->withHeaders($this->auth)->get('api/v1/products?perPage=1');
        $this->assertStringContainsString('rel="first"', $normal->response()->getHeaderLine('Link'));
    }

    public function testDeepOffsetIsRefusedAndSteeredToCursor(): void
    {
        // An unbounded OFFSET makes the DB walk+discard that many rows per request, so
        // `?page=<huge>` on a large table is an amplification DoS on an exposed service.
        // Past MAX_OFFSET the request is refused (before any COUNT/scan) with a 400 that
        // names the scalable alternative — cursor pagination.
        $this->seedProducts(1);

        // MAX_OFFSET is 100000; perPage clamps to 100 → page 1002 = offset 100100.
        $result = $this->withHeaders($this->auth)->get('api/v1/products?perPage=100&page=1002');
        $result->assertStatus(400);

        $json = json_decode((string) $result->response()->getBody(), true);
        $this->assertStringContainsString('application/problem+json', $result->response()->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('cursor', strtolower($json['detail']));

        // A normal (shallow) page is unaffected.
        $this->withHeaders($this->auth)->get('api/v1/products?perPage=100&page=2')->assertStatus(200);
    }

    public function testOffsetResponseCarriesRfc8288LinkHeader(): void
    {
        $this->seedProducts(5); // perPage 2 → 3 pages

        $link = $this->withHeaders($this->auth)->get('api/v1/products?perPage=2&page=1&filter[status]=active')
            ->response()->getHeaderLine('Link');

        $this->assertStringContainsString('rel="next"', $link);
        $this->assertStringContainsString('page=2', $link);
        $this->assertStringContainsString('rel="last"', $link);
        $this->assertStringNotContainsString('rel="prev"', $link);  // page 1 has no prev
        $this->assertStringContainsString('filter', $link);         // preserves other params
        $this->assertStringNotContainsString('index.php', $link);   // never leak the front controller
    }

    public function testCursorResponseCarriesLinkNextHeader(): void
    {
        $this->seedProducts(3); // perPage 2 → hasMore on page 1

        $link = $this->withHeaders($this->auth)->get('api/v1/products?cursor=&perPage=2')
            ->response()->getHeaderLine('Link');

        $this->assertStringContainsString('rel="next"', $link);
        $this->assertStringContainsString('cursor=', $link);
        $this->assertStringNotContainsString('rel="prev"', $link); // keyset is forward-only
    }

    public function testLinkHeaderEncodesReflectedQueryNoCrlfHeaderInjection(): void
    {
        // The Link header echoes the request query in every rel, so a caller-supplied
        // filter value flows into a response header. It MUST be percent-encoded (the
        // header is built with http_build_query), or a CRLF in the value would split
        // the response / inject a header. Regression guard against a future refactor to
        // manual string concatenation.
        $this->seedProducts(3);

        $link = $this->withHeaders($this->auth)
            ->get('api/v1/products?perPage=1&filter[status]=active%0d%0aX-Injected:%20pwned')
            ->response()->getHeaderLine('Link');

        // No raw CR/LF may reach the header value — that is the response-splitting vector.
        $this->assertStringNotContainsString("\r", $link);
        $this->assertStringNotContainsString("\n", $link);
        // The malicious value is reflected only in encoded form (CRLF → %0D%0A), never as
        // a decoded `X-Injected: ` header break.
        $this->assertStringContainsStringIgnoringCase('%0d%0a', $link);
        $this->assertStringNotContainsString('X-Injected: pwned', $link);
        // Still a valid Link header.
        $this->assertStringContainsString('rel="first"', $link);
    }
}

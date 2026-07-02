<?php

declare(strict_types=1);

use Tests\Support\FeatureTestCase;

/**
 * SQL-injection resistance (the top "in case exposed" concern): filter/data VALUES are
 * bound parameters, and columns/operators are allow-listed. A tautology or statement in
 * a value is inert data; an injected column/operator is a loud 400 — never interpolated
 * into SQL. These pin that against a refactor to string-built queries.
 *
 * @internal
 */
final class SqlInjectionTest extends FeatureTestCase
{
    /** @var array<string, string> */
    private array $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auth = $this->authHeaders(['products:*']);
    }

    private function create(array $body)
    {
        return $this->withHeaders($this->auth)->withBodyFormat('json')->post('api/v1/products', $body);
    }

    private function q(string $query)
    {
        return $this->withHeaders($this->auth)->get('api/v1/products?' . $query);
    }

    private function total(): int
    {
        return json_decode((string) $this->q('perPage=1')->response()->getBody(), true)['meta']['pagination']['total'];
    }

    public function testTautologyInAFilterValueMatchesNothingNotEverything(): void
    {
        $this->create(['sku' => 'INJ-1', 'name' => 'n', 'price' => '1.00', 'status' => 'active']);
        $this->assertGreaterThan(0, $this->total());

        // `active' OR '1'='1` in a VALUE must be bound literally (no product has that
        // literal status), returning 0 rows — not concatenated into WHERE to match all.
        $rows = json_decode((string) $this->q('filter[status]=' . rawurlencode("active' OR '1'='1"))->response()->getBody(), true)['data'];

        $this->assertCount(0, $rows, 'a tautology in a filter value must be parameterised (match nothing), not injected');
    }

    public function testStoredSqlRoundTripsLiterallyAndTheTableSurvives(): void
    {
        $before  = $this->total();
        $payload = "'; DROP TABLE products; --";

        $create = $this->create(['sku' => $payload, 'name' => 'n', 'price' => '1.00', 'status' => 'active']);
        $create->assertStatus(201);
        $id = json_decode((string) $create->response()->getBody(), true)['data']['id'];

        // The "DROP TABLE" was inert data: the table survives and grew by exactly one...
        $this->assertSame($before + 1, $this->total());
        // ...and the value round-trips byte-for-byte (bound on write and read).
        $shown = json_decode((string) $this->withHeaders($this->auth)->get("api/v1/products/{$id}")->response()->getBody(), true)['data'];
        $this->assertSame($payload, $shown['sku']);
    }

    public function testInjectedColumnsAndOperatorsAreRejectedWith400(): void
    {
        // Columns (sort/fields) and operators are allow-listed — an injected one never
        // reaches SQL; it fails loudly.
        $this->q('sort=' . rawurlencode('id;DROP TABLE products'))->assertStatus(400);
        $this->q('fields=' . rawurlencode('sku,(SELECT secret_hash FROM api_keys)'))->assertStatus(400);
        // A numeric column rejects a non-numeric value, so a UNION payload can't ride in.
        $this->q('filter[id][gt]=' . rawurlencode('0 UNION SELECT 1'))->assertStatus(400);
    }
}

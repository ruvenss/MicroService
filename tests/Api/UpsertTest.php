<?php

declare(strict_types=1);

use Tests\Support\FeatureTestCase;

/**
 * Collection PUT = upsert (create-or-update) by the declared natural key
 * (`upsertKey` = sku for products). Lets an n8n sync reconcile records in one
 * idempotent call instead of GET-then-POST/PATCH.
 *
 * @internal
 */
final class UpsertTest extends FeatureTestCase
{
    /** @var array<string, string> */
    private array $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auth = $this->authHeaders(['products:*']);
    }

    /** @return array{data: mixed, meta: array<string,int>} */
    private function sync(array|string $body): array
    {
        return json_decode((string) $this->withHeaders($this->auth)->withBodyFormat('json')
            ->put('api/v1/products', $body)->response()->getBody(), true);
    }

    private function total(): int
    {
        return json_decode((string) $this->withHeaders($this->auth)->get('api/v1/products')->response()->getBody(), true)['meta']['pagination']['total'];
    }

    public function testSingleUpsertCreatesThenUpdatesByKey(): void
    {
        // First call → create.
        $created = $this->sync(['sku' => 'SYNC-1', 'name' => 'First', 'price' => '1.00']);
        $this->assertSame(1, $created['meta']['created']);
        $this->assertSame(0, $created['meta']['updated']);
        $this->assertSame('First', $created['data']['name']);
        $this->assertSame(1, $this->total());

        // Same sku again → update (no duplicate row).
        $updated = $this->sync(['sku' => 'SYNC-1', 'name' => 'Renamed', 'price' => '2.50']);
        $this->assertSame(0, $updated['meta']['created']);
        $this->assertSame(1, $updated['meta']['updated']);
        $this->assertSame('Renamed', $updated['data']['name']);
        $this->assertSame(2.5, $updated['data']['price']); // cast preserved
        $this->assertSame(1, $this->total());              // still one row
    }

    public function testBulkUpsertMixesCreatesAndUpdates(): void
    {
        $this->sync(['sku' => 'B-1', 'name' => 'Existing', 'price' => '1.00']); // pre-existing

        $result = $this->sync([
            ['sku' => 'B-1', 'name' => 'Updated', 'price' => '9.00'], // update
            ['sku' => 'B-2', 'name' => 'New A', 'price' => '2.00'],   // create
            ['sku' => 'B-3', 'name' => 'New B', 'price' => '3.00'],   // create
        ]);

        $this->assertSame(3, $result['meta']['upserted']);
        $this->assertSame(2, $result['meta']['created']);
        $this->assertSame(1, $result['meta']['updated']);
        $this->assertSame(3, $this->total());
    }

    public function testUpsertIsAllOrNothingOnInvalidItem(): void
    {
        $this->withHeaders($this->auth)->withBodyFormat('json')->put('api/v1/products', [
            ['sku' => 'OK-1', 'name' => 'Fine', 'price' => '1.00'],
            ['sku' => 'BAD-1', 'price' => '2.00'], // missing name (create rules)
        ])->assertStatus(422);

        $this->assertSame(0, $this->total()); // nothing written
    }

    public function testUpsertRejectsItemWithoutTheKey(): void
    {
        $this->withHeaders($this->auth)->withBodyFormat('json')
            ->put('api/v1/products', ['name' => 'No sku', 'price' => '1.00'])
            ->assertStatus(422);
    }

    public function testUpsertRejectsDuplicateKeyInBatch(): void
    {
        $this->withHeaders($this->auth)->withBodyFormat('json')->put('api/v1/products', [
            ['sku' => 'DUP', 'name' => 'One', 'price' => '1.00'],
            ['sku' => 'DUP', 'name' => 'Two', 'price' => '2.00'],
        ])->assertStatus(422);

        $this->assertSame(0, $this->total());
    }

    public function testUpsertRequiresWriteScope(): void
    {
        $this->withHeaders($this->authHeaders(['products:read']))->withBodyFormat('json')
            ->put('api/v1/products', ['sku' => 'X', 'name' => 'N', 'price' => '1.00'])
            ->assertStatus(403);
    }
}

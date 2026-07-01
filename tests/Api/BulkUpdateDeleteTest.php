<?php

declare(strict_types=1);

use Tests\Support\FeatureTestCase;

/**
 * Collection-level bulk update (PATCH) and bulk delete (DELETE) — one call per
 * batch, all-or-nothing, so an n8n workflow can mutate many rows at once.
 *
 * @internal
 */
final class BulkUpdateDeleteTest extends FeatureTestCase
{
    /** @var array<string, string> */
    private array $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auth = $this->authHeaders(['products:*']);
    }

    /**
     * Seed rows and return their ids.
     *
     * @return list<int>
     */
    private function seedProducts(int $count): array
    {
        $items = [];
        for ($i = 1; $i <= $count; $i++) {
            $items[] = ['sku' => "SEED-{$i}", 'name' => "Name {$i}", 'price' => '1.00'];
        }
        $json = json_decode(
            (string) $this->withHeaders($this->auth)->withBodyFormat('json')->post('api/v1/products', $items)->response()->getBody(),
            true,
        );

        return array_map(static fn (array $row): int => (int) $row['id'], $json['data']);
    }

    private function total(): int
    {
        return json_decode((string) $this->withHeaders($this->auth)->get('api/v1/products')->response()->getBody(), true)['meta']['pagination']['total'];
    }

    public function testBulkUpdatesEveryItem(): void
    {
        [$a, $b] = $this->seedProducts(2);

        $result = $this->withHeaders($this->auth)->withBodyFormat('json')->patch('api/v1/products', [
            ['id' => $a, 'name' => 'Alpha'],
            ['id' => $b, 'price' => '9.99'],
        ]);

        $result->assertStatus(200);
        $json = json_decode((string) $result->response()->getBody(), true);
        $this->assertSame(2, $json['meta']['updated']);

        $rowA = json_decode((string) $this->withHeaders($this->auth)->get("api/v1/products/{$a}")->response()->getBody(), true)['data'];
        $rowB = json_decode((string) $this->withHeaders($this->auth)->get("api/v1/products/{$b}")->response()->getBody(), true)['data'];
        $this->assertSame('Alpha', $rowA['name']);
        $this->assertSame(9.99, $rowB['price']); // cast still applied
    }

    public function testBulkUpdateIsAllOrNothing(): void
    {
        [$a] = $this->seedProducts(1);

        $result = $this->withHeaders($this->auth)->withBodyFormat('json')->patch('api/v1/products', [
            ['id' => $a, 'name' => 'Changed'],
            ['id' => 999999, 'name' => 'Ghost'], // does not exist
        ]);

        $result->assertStatus(422);
        $json = json_decode((string) $result->response()->getBody(), true);
        $this->assertArrayHasKey('1', $json['errors']);

        // untouched: the valid row was not written
        $row = json_decode((string) $this->withHeaders($this->auth)->get("api/v1/products/{$a}")->response()->getBody(), true)['data'];
        $this->assertSame('Name 1', $row['name']);
    }

    public function testBulkUpdateRejectsItemWithoutPrimaryKey(): void
    {
        $this->seedProducts(1);
        $this->withHeaders($this->auth)->withBodyFormat('json')
            ->patch('api/v1/products', [['name' => 'No id here']])
            ->assertStatus(422);
    }

    public function testBulkDeleteArchivesEveryId(): void
    {
        [$a, $b] = $this->seedProducts(3); // a third row is seeded and must survive

        $result = $this->withHeaders($this->auth)->withBodyFormat('json')
            ->delete('api/v1/products', ['ids' => [$a, $b]]);

        $result->assertStatus(200);
        $json = json_decode((string) $result->response()->getBody(), true);
        $this->assertSame(2, $json['meta']['deleted']);
        $this->assertSame(1, $this->total()); // only $c remains

        // deleted rows are archived (not hard-deleted) and restorable from the recycle bin
        $this->withHeaders($this->auth)->get("api/v1/products/{$a}")->assertStatus(404);
        $archiveAuth = $this->authHeaders(['archive:read']);
        $archived    = json_decode((string) $this->withHeaders($archiveAuth)->get('api/v1/_archive')->response()->getBody(), true)['data'];
        $this->assertGreaterThanOrEqual(2, count($archived));
    }

    public function testBulkDeleteIsAllOrNothing(): void
    {
        [$a, $b] = $this->seedProducts(2);

        $this->withHeaders($this->auth)->withBodyFormat('json')
            ->delete('api/v1/products', ['ids' => [$a, 999999, $b]])
            ->assertStatus(422);

        // nothing deleted
        $this->assertSame(2, $this->total());
    }

    public function testBulkDeleteAcceptsBareArray(): void
    {
        [$a] = $this->seedProducts(1);

        $this->withHeaders($this->auth)->withBodyFormat('json')
            ->delete('api/v1/products', [$a])
            ->assertStatus(200);
        $this->assertSame(0, $this->total());
    }

    public function testBulkDeleteRequiresDeleteScope(): void
    {
        [$a] = $this->seedProducts(1);
        $readOnly = $this->authHeaders(['products:read']);

        $this->withHeaders($readOnly)->withBodyFormat('json')
            ->delete('api/v1/products', ['ids' => [$a]])
            ->assertStatus(403);
    }
}

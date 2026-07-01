<?php

declare(strict_types=1);

use Tests\Support\FeatureTestCase;

/**
 * Bulk create: POST a JSON array to insert many rows in one call (all-or-nothing).
 *
 * @internal
 */
final class BulkCreateTest extends FeatureTestCase
{
    /** @var array<string, string> */
    private array $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auth = $this->authHeaders(['products:*']);
    }

    private function total(): int
    {
        return json_decode((string) $this->withHeaders($this->auth)->get('api/v1/products')->response()->getBody(), true)['meta']['pagination']['total'];
    }

    public function testBulkCreatesAllItems(): void
    {
        $result = $this->withHeaders($this->auth)->withBodyFormat('json')->post('api/v1/products', [
            ['sku' => 'BULK-1', 'name' => 'One', 'price' => '1.00'],
            ['sku' => 'BULK-2', 'name' => 'Two', 'price' => '2.00'],
            ['sku' => 'BULK-3', 'name' => 'Three', 'price' => '3.00'],
        ]);

        $result->assertStatus(201);
        $json = json_decode((string) $result->response()->getBody(), true);
        $this->assertSame(3, $json['meta']['created']);
        $this->assertCount(3, $json['data']);
        $this->assertIsInt($json['data'][0]['id']); // casts still applied
        $this->assertSame(3, $this->total());
    }

    public function testAnyInvalidItemRejectsTheWholeBatch(): void
    {
        $result = $this->withHeaders($this->auth)->withBodyFormat('json')->post('api/v1/products', [
            ['sku' => 'OK-1', 'name' => 'One', 'price' => '1.00'],
            ['sku' => 'BAD', 'price' => '2.00'], // missing name
        ]);

        $result->assertStatus(422);
        $json = json_decode((string) $result->response()->getBody(), true);
        $this->assertArrayHasKey('1', $json['errors']);
        $this->assertArrayHasKey('name', $json['errors']['1']);

        // all-or-nothing: nothing was created
        $this->assertSame(0, $this->total());
    }

    public function testBatchSizeIsCapped(): void
    {
        $items = [];
        for ($i = 0; $i < 101; $i++) {
            $items[] = ['sku' => "CAP-{$i}", 'name' => 'N', 'price' => '1.00'];
        }

        $this->withHeaders($this->auth)->withBodyFormat('json')->post('api/v1/products', $items)->assertStatus(422);
        $this->assertSame(0, $this->total());
    }

    public function testSingleObjectStillWorks(): void
    {
        $this->withHeaders($this->auth)->withBodyFormat('json')
            ->post('api/v1/products', ['sku' => 'SINGLE-1', 'name' => 'N', 'price' => '1.00'])
            ->assertStatus(201);
    }
}

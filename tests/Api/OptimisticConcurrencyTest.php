<?php

declare(strict_types=1);

use Tests\Support\FeatureTestCase;

/**
 * Optimistic concurrency via If-Match / ETag (RFC 9110): a conditional PATCH/PUT/
 * DELETE is refused with 412 when the record changed since it was fetched, so two
 * n8n workflows editing the same record can't silently clobber each other.
 *
 * @internal
 */
final class OptimisticConcurrencyTest extends FeatureTestCase
{
    /** @var array<string, string> */
    private array $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auth = $this->authHeaders(['products:*']);
    }

    private function createProduct(): int
    {
        return json_decode((string) $this->withHeaders($this->auth)->withBodyFormat('json')
            ->post('api/v1/products', ['sku' => 'CC-1', 'name' => 'N', 'price' => '1.00'])
            ->response()->getBody(), true)['data']['id'];
    }

    private function etagOf(int $id): string
    {
        return $this->withHeaders($this->auth)->get("api/v1/products/{$id}")->response()->getHeaderLine('ETag');
    }

    public function testMatchingIfMatchAllowsTheUpdate(): void
    {
        $id   = $this->createProduct();
        $etag = $this->etagOf($id);

        $this->withHeaders($this->auth + ['If-Match' => $etag])->withBodyFormat('json')
            ->patch("api/v1/products/{$id}", ['name' => 'Renamed'])
            ->assertStatus(200);
    }

    public function testStaleIfMatchIsRefusedWith412(): void
    {
        $id   = $this->createProduct();
        $etag = $this->etagOf($id);

        // Someone else updates the record first → the ETag we hold is now stale.
        $this->withHeaders($this->auth)->withBodyFormat('json')->patch("api/v1/products/{$id}", ['name' => 'ChangedByOther']);

        // Our conditional write must be refused (lost-update prevented).
        $result = $this->withHeaders($this->auth + ['If-Match' => $etag])->withBodyFormat('json')
            ->patch("api/v1/products/{$id}", ['name' => 'MyChange']);
        $result->assertStatus(412);

        // And the record still holds the other writer's value.
        $row = json_decode((string) $this->withHeaders($this->auth)->get("api/v1/products/{$id}")->response()->getBody(), true)['data'];
        $this->assertSame('ChangedByOther', $row['name']);
    }

    public function testWildcardIfMatchSucceedsWhenRecordExists(): void
    {
        $id = $this->createProduct();
        $this->withHeaders($this->auth + ['If-Match' => '*'])->withBodyFormat('json')
            ->patch("api/v1/products/{$id}", ['name' => 'Any'])
            ->assertStatus(200);
    }

    public function testNoIfMatchIsUnconditional(): void
    {
        $id = $this->createProduct();
        $this->withHeaders($this->auth)->withBodyFormat('json')
            ->patch("api/v1/products/{$id}", ['name' => 'Unconditional'])
            ->assertStatus(200);
    }

    public function testStaleIfMatchAlsoBlocksDelete(): void
    {
        $id   = $this->createProduct();
        $etag = $this->etagOf($id);

        $this->withHeaders($this->auth)->withBodyFormat('json')->patch("api/v1/products/{$id}", ['name' => 'Moved']);

        $this->withHeaders($this->auth + ['If-Match' => $etag])->delete("api/v1/products/{$id}")->assertStatus(412);
        // Correct current ETag lets the delete through.
        $this->withHeaders($this->auth + ['If-Match' => $this->etagOf($id)])->delete("api/v1/products/{$id}")->assertStatus(204);
    }
}

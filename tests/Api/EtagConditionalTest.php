<?php

declare(strict_types=1);

use Tests\Support\FeatureTestCase;

/**
 * Conditional GET: reads carry an ETag; a matching If-None-Match short-circuits
 * to 304 Not Modified, so an n8n poll that sees no change transfers no body.
 *
 * @internal
 */
final class EtagConditionalTest extends FeatureTestCase
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
        $json = json_decode(
            (string) $this->withHeaders($this->auth)->withBodyFormat('json')
                ->post('api/v1/products', ['sku' => 'ET-1', 'name' => 'Tag', 'price' => '1.00'])
                ->response()->getBody(),
            true,
        );

        return (int) $json['data']['id'];
    }

    public function testShowReturnsEtagThen304(): void
    {
        $id = $this->createProduct();

        $first = $this->withHeaders($this->auth)->get("api/v1/products/{$id}");
        $first->assertStatus(200);
        $etag = $first->response()->getHeaderLine('ETag');
        $this->assertNotSame('', $etag);

        $second = $this->withHeaders($this->auth + ['If-None-Match' => $etag])->get("api/v1/products/{$id}");
        $second->assertStatus(304);
        $this->assertSame('', (string) $second->response()->getBody());
        $this->assertSame($etag, $second->response()->getHeaderLine('ETag'));
    }

    public function testListReturnsEtagThen304(): void
    {
        $this->createProduct();

        $etag = $this->withHeaders($this->auth)->get('api/v1/products')->response()->getHeaderLine('ETag');
        $this->assertNotSame('', $etag);

        $this->withHeaders($this->auth + ['If-None-Match' => $etag])->get('api/v1/products')->assertStatus(304);
    }

    public function testEtagChangesAfterMutationSoPollGetsFreshData(): void
    {
        $id   = $this->createProduct();
        $etag = $this->withHeaders($this->auth)->get("api/v1/products/{$id}")->response()->getHeaderLine('ETag');

        $this->withHeaders($this->auth)->withBodyFormat('json')->put("api/v1/products/{$id}", ['name' => 'Renamed']);

        // Same old validator must now miss → full 200 with a different ETag.
        $after = $this->withHeaders($this->auth + ['If-None-Match' => $etag])->get("api/v1/products/{$id}");
        $after->assertStatus(200);
        $this->assertNotSame($etag, $after->response()->getHeaderLine('ETag'));
    }

    public function testWildcardIfNoneMatchYields304(): void
    {
        $id = $this->createProduct();
        $this->withHeaders($this->auth + ['If-None-Match' => '*'])->get("api/v1/products/{$id}")->assertStatus(304);
    }

    public function testNonMatchingValidatorStillReturnsBody(): void
    {
        $id = $this->createProduct();
        $this->withHeaders($this->auth + ['If-None-Match' => '"deadbeef"'])
            ->get("api/v1/products/{$id}")
            ->assertStatus(200);
    }
}

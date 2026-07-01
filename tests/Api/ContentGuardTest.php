<?php

declare(strict_types=1);

use Tests\Support\FeatureTestCase;

/**
 * ContentGuard rejects bad write payloads (after auth/permission pass) with a
 * neutral problem+json before any controller/DB work.
 *
 * @internal
 */
final class ContentGuardTest extends FeatureTestCase
{
    /** @var array<string, string> */
    private array $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auth = $this->authHeaders(['products:write']);
    }

    public function testRejectsNonJsonContentType(): void
    {
        $result = $this->withHeaders($this->auth + ['Content-Type' => 'text/plain'])
            ->withBody('hello')
            ->post('api/v1/products');

        $result->assertStatus(415);
        $this->assertStringContainsString('application/problem+json', $result->response()->getHeaderLine('Content-Type'));
    }

    public function testRejectsMalformedJson(): void
    {
        $result = $this->withHeaders($this->auth + ['Content-Type' => 'application/json'])
            ->withBody('{not valid json')
            ->post('api/v1/products');

        $result->assertStatus(400);
        $json = json_decode((string) $result->response()->getBody(), true);
        $this->assertSame('Bad Request', $json['title']);
    }

    public function testRejectsOversizedBodyByActualLength(): void
    {
        // ~1.1 MiB of valid JSON — over the 1 MiB cap. Rejected before any parsing,
        // with a neutral problem+json (no engine detail).
        $huge   = '{"name":"' . str_repeat('a', 1_100_000) . '"}';
        $result = $this->withHeaders($this->auth + ['Content-Type' => 'application/json'])
            ->withBody($huge)
            ->post('api/v1/products');

        $result->assertStatus(413);
        $this->assertStringContainsString('application/problem+json', $result->response()->getHeaderLine('Content-Type'));
        $this->assertSame('Content Too Large', json_decode((string) $result->response()->getBody(), true)['title']);
    }

    public function testRejectsOversizedBodyByContentLengthHeader(): void
    {
        // A lying/huge Content-Length is refused without reading a big body.
        $this->withHeaders($this->auth + ['Content-Type' => 'application/json', 'Content-Length' => '5000000'])
            ->withBody('{"name":"x"}')
            ->post('api/v1/products')
            ->assertStatus(413);
    }

    public function testNormalBodyStillPasses(): void
    {
        $this->withHeaders($this->auth + ['Content-Type' => 'application/json'])
            ->withBody('{"sku":"OK-SIZE","name":"fine","price":"1.00"}')
            ->post('api/v1/products')
            ->assertStatus(201);
    }
}

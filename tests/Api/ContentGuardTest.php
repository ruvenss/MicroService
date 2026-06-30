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
}

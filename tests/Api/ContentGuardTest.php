<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * ContentGuard rejects bad write payloads before any controller/DB work, with a
 * neutral problem+json. These cases short-circuit, so no database is needed.
 *
 * @internal
 */
final class ContentGuardTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testRejectsNonJsonContentType(): void
    {
        $result = $this->withHeaders(['Content-Type' => 'text/plain'])
            ->withBody('hello')
            ->post('api/v1/products');

        $result->assertStatus(415);
        $this->assertStringContainsString('application/problem+json', $result->response()->getHeaderLine('Content-Type'));
    }

    public function testRejectsMalformedJson(): void
    {
        $result = $this->withHeaders(['Content-Type' => 'application/json'])
            ->withBody('{not valid json')
            ->post('api/v1/products');

        $result->assertStatus(400);
        $json = json_decode((string) $result->response()->getBody(), true);
        $this->assertSame('Bad Request', $json['title']);
    }
}

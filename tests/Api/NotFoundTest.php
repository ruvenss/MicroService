<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * The 404 override must return a neutral problem+json body that discloses
 * nothing about the framework or routing.
 *
 * @internal
 */
final class NotFoundTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testUnknownPathReturnsNeutralProblemJson(): void
    {
        $result   = $this->get('no/such/route');
        $response = $result->response();

        $result->assertStatus(404);
        $this->assertStringContainsString('application/problem+json', $response->getHeaderLine('Content-Type'));

        $json = json_decode((string) $response->getBody(), true);
        $this->assertSame('Not Found', $json['title']);
        $this->assertSame(404, $json['status']);
        // Must NOT leak framework identity.
        $this->assertStringNotContainsStringIgnoringCase('codeigniter', (string) $response->getBody());
    }
}

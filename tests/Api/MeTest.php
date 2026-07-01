<?php

declare(strict_types=1);

use Tests\Support\FeatureTestCase;

/**
 * @internal
 */
final class MeTest extends FeatureTestCase
{
    public function testReturnsTheCallersKeyInfo(): void
    {
        $result = $this->withHeaders($this->authHeaders(['products:read', 'audit:read']))->get('api/v1/_me');
        $result->assertStatus(200);

        $data = json_decode((string) $result->response()->getBody(), true)['data'];
        $this->assertContains('products:read', $data['scopes']);
        $this->assertContains('audit:read', $data['scopes']);
        $this->assertArrayHasKey('name', $data);
        $this->assertArrayHasKey('expiresAt', $data);
    }

    public function testNeverLeaksTheSecret(): void
    {
        $body = (string) $this->withHeaders($this->authHeaders(['products:read']))->get('api/v1/_me')->response()->getBody();

        $this->assertStringNotContainsStringIgnoringCase('secret', $body);
        $this->assertStringNotContainsStringIgnoringCase('hash', $body);
    }

    public function testRequiresAuthentication(): void
    {
        $this->get('api/v1/_me')->assertStatus(401);
    }
}

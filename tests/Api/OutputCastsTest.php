<?php

declare(strict_types=1);

use Tests\Support\FeatureTestCase;

/**
 * Declared casts give responses proper JSON types (int/float) instead of
 * MySQLi's all-strings — so an n8n workflow gets typed fields directly.
 *
 * @internal
 */
final class OutputCastsTest extends FeatureTestCase
{
    public function testCreatedResourceHasTypedFields(): void
    {
        $body = json_decode((string) $this->withHeaders($this->authHeaders(['products:*']))
            ->withBodyFormat('json')
            ->post('api/v1/products', ['sku' => 'CAST-1', 'name' => 'N', 'price' => '9.99'])
            ->response()->getBody(), true);

        $this->assertIsInt($body['data']['id']);
        $this->assertIsFloat($body['data']['price']);
        $this->assertEqualsWithDelta(9.99, $body['data']['price'], 0.0001);
        $this->assertIsString($body['data']['sku']); // uncast columns stay as-is
    }

    public function testGetResponsesEmitRawUtf8AndUnescapedSlashes(): void
    {
        // Reads go through respondCacheable's manual json_encode; it must use the same
        // unescaped flags as writes (Config\Format) so unicode and slashes reach n8n /
        // a human in Postman raw ("Café/☕"), not "Café\/☕". Asserts on the raw
        // body bytes, since json_decode would hide the escaping either way.
        $auth = $this->authHeaders(['products:*']);
        $this->withHeaders($auth)->withBodyFormat('json')
            ->post('api/v1/products', ['sku' => 'UTFWIRE1', 'name' => 'Café/☕', 'price' => '1.00']);

        $raw = (string) $this->withHeaders($auth)->get('api/v1/products?filter[sku]=UTFWIRE1')->response()->getBody();

        $backslash = chr(92);
        $this->assertStringContainsString('Café/☕', $raw);              // raw UTF-8 + unescaped slash
        $this->assertStringNotContainsString($backslash . 'u', $raw);    // no \uXXXX unicode escape
        $this->assertStringNotContainsString($backslash . '/', $raw);    // no \/ escaped slash
    }

    public function testListRowsAreTyped(): void
    {
        $auth = $this->authHeaders(['products:*']);
        $this->withHeaders($auth)->withBodyFormat('json')->post('api/v1/products', ['sku' => 'CAST-2', 'name' => 'N', 'price' => '1.50']);

        $json = json_decode((string) $this->withHeaders($auth)->get('api/v1/products')->response()->getBody(), true);

        $this->assertIsInt($json['data'][0]['id']);
        $this->assertIsFloat($json['data'][0]['price']);
    }

    public function testTimestampsAreIso8601Utc(): void
    {
        $body = json_decode((string) $this->withHeaders($this->authHeaders(['products:*']))
            ->withBodyFormat('json')
            ->post('api/v1/products', ['sku' => 'CAST-DT', 'name' => 'N', 'price' => '1.00'])
            ->response()->getBody(), true);

        // e.g. 2026-07-01T10:19:30Z — unambiguous UTC, not a bare "Y-m-d H:i:s".
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $body['data']['created_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $body['data']['updated_at']);
    }
}

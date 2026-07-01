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

    public function testListRowsAreTyped(): void
    {
        $auth = $this->authHeaders(['products:*']);
        $this->withHeaders($auth)->withBodyFormat('json')->post('api/v1/products', ['sku' => 'CAST-2', 'name' => 'N', 'price' => '1.50']);

        $json = json_decode((string) $this->withHeaders($auth)->get('api/v1/products')->response()->getBody(), true);

        $this->assertIsInt($json['data'][0]['id']);
        $this->assertIsFloat($json['data'][0]['price']);
    }
}

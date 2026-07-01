<?php

declare(strict_types=1);

use Tests\Support\FeatureTestCase;

/**
 * End-to-end filtering and sparse fieldsets against the real test database.
 *
 * @internal
 */
final class QueryFilterTest extends FeatureTestCase
{
    /** @var array<string, string> */
    private array $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auth = $this->authHeaders(['products:*']);
        $this->seedProduct('cheap', 'active', '5.00');
        $this->seedProduct('pricey', 'archived', '50.00');
    }

    private function seedProduct(string $sku, string $status, string $price): void
    {
        $this->withHeaders($this->auth)->withBodyFormat('json')->post('api/v1/products', [
            'sku' => $sku, 'name' => ucfirst($sku), 'price' => $price, 'status' => $status,
        ]);
    }

    private function list(string $query): array
    {
        $body = (string) $this->withHeaders($this->auth)->get('api/v1/products?' . $query)->response()->getBody();

        return json_decode($body, true);
    }

    public function testFilterByStatus(): void
    {
        $json = $this->list('filter[status]=active');

        $this->assertCount(1, $json['data']);
        $this->assertSame('cheap', $json['data'][0]['sku']);
    }

    public function testFilterByPriceGreaterThan(): void
    {
        $json = $this->list('filter[price][gt]=10');

        $this->assertCount(1, $json['data']);
        $this->assertSame('pricey', $json['data'][0]['sku']);
    }

    public function testSparseFieldsLimitColumns(): void
    {
        $json = $this->list('fields=id,sku&perPage=1');

        $this->assertSame(['id', 'sku'], array_keys($json['data'][0]));
    }

    public function testInvalidFilterColumnReturns400(): void
    {
        $this->withHeaders($this->auth)->get('api/v1/products?filter[bogus]=x')->assertStatus(400);
    }

    public function testInvalidFieldReturns400(): void
    {
        $this->withHeaders($this->auth)->get('api/v1/products?fields=secret')->assertStatus(400);
    }

    private function seedNamed(string $sku, string $name, string $price): void
    {
        $this->withHeaders($this->auth)->withBodyFormat('json')->post('api/v1/products', [
            'sku' => $sku, 'name' => $name, 'price' => $price, 'status' => 'active',
        ]);
    }

    public function testSortsByMultipleColumns(): void
    {
        $this->seedNamed('t1', 'Bravo', '10.00');
        $this->seedNamed('t2', 'Alpha', '10.00');
        $this->seedNamed('t3', 'Zulu', '5.00');

        // price ascending, ties broken by name ascending.
        $skus = array_column($this->list('sort=price,name&perPage=100')['data'], 'sku');
        $this->assertLessThan(array_search('t2', $skus, true), array_search('t3', $skus, true)); // price 5 before price 10
        $this->assertLessThan(array_search('t1', $skus, true), array_search('t2', $skus, true)); // Alpha before Bravo at price 10

        // Reverse the primary column; secondary still ascending.
        $skus = array_column($this->list('sort=-price,name&perPage=100')['data'], 'sku');
        $this->assertLessThan(array_search('t3', $skus, true), array_search('t2', $skus, true)); // price 10 before price 5
        $this->assertLessThan(array_search('t1', $skus, true), array_search('t2', $skus, true)); // Alpha before Bravo
    }

    public function testUnknownSortColumnIsIgnoredNotInjected(): void
    {
        // A bogus sort column is dropped (allow-listed); the valid one still applies.
        $skus = array_column($this->list('sort=bogus,-price&perPage=100')['data'], 'sku');
        $this->assertSame('pricey', $skus[0]); // -price → highest first
    }
}

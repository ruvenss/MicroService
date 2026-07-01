<?php

declare(strict_types=1);

use Tests\Support\FeatureTestCase;

/**
 * @internal
 */
final class ArchivalDeleteTest extends FeatureTestCase
{
    private function createProduct(array $headers): string
    {
        return (string) json_decode((string) $this->withHeaders($headers)->withBodyFormat('json')
            ->post('api/v1/products', ['sku' => 'SKU-' . uniqid(), 'name' => 'Temp', 'price' => '1.00'])
            ->response()->getBody(), true)['data']['id'];
    }

    private function latestArchiveId(array $headers): int
    {
        return json_decode((string) $this->withHeaders($headers)->get('api/v1/_archive')->response()->getBody(), true)['data'][0]['id'];
    }

    public function testDeleteMovesRowToArchive(): void
    {
        $headers = $this->authHeaders(['products:*', 'archive:read']);
        $id      = $this->createProduct($headers);

        $this->withHeaders($headers)->delete("api/v1/products/{$id}")->assertStatus(204);
        $this->withHeaders($headers)->get("api/v1/products/{$id}")->assertStatus(404);

        $json = json_decode((string) $this->withHeaders($headers)->get('api/v1/_archive')->response()->getBody(), true);
        $this->assertNotEmpty($json['data']);
        $this->assertSame((string) $id, $json['data'][0]['record_id']);
    }

    public function testRestoreBringsTheRowBack(): void
    {
        $headers = $this->authHeaders(['products:*', 'archive:read', 'archive:write']);
        $id      = $this->createProduct($headers);
        $this->withHeaders($headers)->delete("api/v1/products/{$id}");

        $this->withHeaders($headers)->post('api/v1/_archive/' . $this->latestArchiveId($headers) . '/restore')->assertStatus(200);
        $this->withHeaders($headers)->get("api/v1/products/{$id}")->assertStatus(200);
    }

    public function testDoubleRestoreReturns409(): void
    {
        $headers = $this->authHeaders(['products:*', 'archive:read', 'archive:write']);
        $id      = $this->createProduct($headers);
        $this->withHeaders($headers)->delete("api/v1/products/{$id}");
        $archiveId = $this->latestArchiveId($headers);

        $this->withHeaders($headers)->post("api/v1/_archive/{$archiveId}/restore")->assertStatus(200);
        $this->withHeaders($headers)->post("api/v1/_archive/{$archiveId}/restore")->assertStatus(409);
    }

    public function testRestoreRequiresWriteScope(): void
    {
        $headers = $this->authHeaders(['products:*', 'archive:read']); // no archive:write
        $id      = $this->createProduct($headers);
        $this->withHeaders($headers)->delete("api/v1/products/{$id}");

        $this->withHeaders($headers)->post('api/v1/_archive/' . $this->latestArchiveId($headers) . '/restore')->assertStatus(403);
    }

    public function testArchiveListRequiresReadScope(): void
    {
        $this->withHeaders($this->authHeaders(['products:read']))->get('api/v1/_archive')->assertStatus(403);
    }
}

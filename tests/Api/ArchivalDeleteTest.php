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

    public function testArchiveDeepOffsetIsRefused(): void
    {
        // Same amplification-DoS cap as the resource + audit lists: a deep OFFSET is
        // refused with a 400; normal browsing is unaffected.
        $headers = $this->authHeaders(['archive:read']);

        $this->withHeaders($headers)->get('api/v1/_archive?perPage=100&page=1002')->assertStatus(400); // offset 100100
        $this->withHeaders($headers)->get('api/v1/_archive?perPage=100&page=2')->assertStatus(200);
    }

    private function archiveRecordIds(array $headers, string $query): array
    {
        $data = json_decode((string) $this->withHeaders($headers)->get('api/v1/_archive?' . $query)->response()->getBody(), true)['data'];

        return array_column($data, 'record_id');
    }

    /** The archive-row id for a given original record id (order-independent). */
    private function archiveIdFor(array $headers, string $recordId): int
    {
        $data = json_decode((string) $this->withHeaders($headers)->get('api/v1/_archive?perPage=100')->response()->getBody(), true)['data'];
        foreach ($data as $row) {
            if ((string) $row['record_id'] === $recordId) {
                return (int) $row['id'];
            }
        }

        return 0;
    }

    public function testFilterByRestorationState(): void
    {
        $headers = $this->authHeaders(['products:*', 'archive:read', 'archive:write']);

        $keep    = $this->createProduct($headers);
        $restore = $this->createProduct($headers);
        $this->withHeaders($headers)->delete("api/v1/products/{$keep}");
        $this->withHeaders($headers)->delete("api/v1/products/{$restore}");
        // Restore the specific record (same-second deletes make list order ambiguous).
        $this->withHeaders($headers)->post('api/v1/_archive/' . $this->archiveIdFor($headers, (string) $restore) . '/restore')->assertStatus(200);

        // restored=false → only the still-deleted (restorable) one.
        $stillDeleted = $this->archiveRecordIds($headers, 'restored=false&perPage=100');
        $this->assertContains((string) $keep, $stillDeleted);
        $this->assertNotContains((string) $restore, $stillDeleted);

        // restored=true → only the already-restored one.
        $done = $this->archiveRecordIds($headers, 'restored=true&perPage=100');
        $this->assertContains((string) $restore, $done);
        $this->assertNotContains((string) $keep, $done);
    }

    public function testInvalidRestoredValueReturns400(): void
    {
        $this->withHeaders($this->authHeaders(['archive:read']))->get('api/v1/_archive?restored=maybe')->assertStatus(400);
    }

    public function testArchiveShowPayloadIsCastLikeTheLiveApi(): void
    {
        // Inspecting the recycle bin before a restore must present the record in the
        // same typed, ISO-8601-Z shape as a live GET — not raw MySQLi strings — so an
        // n8n workflow parses payload exactly like the CRUD response. Fractional price
        // so the float survives the JSON round-trip.
        $headers = $this->authHeaders(['products:*', 'archive:read']);
        $id      = (string) json_decode((string) $this->withHeaders($headers)->withBodyFormat('json')
            ->post('api/v1/products', ['sku' => 'SKU-' . uniqid(), 'name' => 'N', 'price' => '4.25'])
            ->response()->getBody(), true)['data']['id'];
        $this->withHeaders($headers)->delete("api/v1/products/{$id}");

        $archiveId = $this->archiveIdFor($headers, $id);
        $payload   = json_decode((string) $this->withHeaders($headers)->get("api/v1/_archive/{$archiveId}")->response()->getBody(), true)['data']['payload'];

        $this->assertIsInt($payload['id']);          // int, not "NN"
        $this->assertSame(4.25, $payload['price']);  // float, not "4.25"
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $payload['created_at']);
    }
}

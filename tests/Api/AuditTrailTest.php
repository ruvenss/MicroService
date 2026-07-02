<?php

declare(strict_types=1);

use App\Models\AuditLogModel;
use Tests\Support\FeatureTestCase;

/**
 * @internal
 */
final class AuditTrailTest extends FeatureTestCase
{
    private function createProduct(array $headers, string $name = 'Name'): string
    {
        return (string) json_decode((string) $this->withHeaders($headers)->withBodyFormat('json')
            ->post('api/v1/products', ['sku' => 'SKU-' . uniqid(), 'name' => $name, 'price' => '1.00'])
            ->response()->getBody(), true)['data']['id'];
    }

    public function testCreateIsAudited(): void
    {
        $this->createProduct($this->authHeaders(['products:*']));

        $rows = (new AuditLogModel())->where('action', 'create')->where('resource', 'products')->findAll();
        $this->assertNotEmpty($rows);
        $this->assertNotNull($rows[0]['after_json']);
        $this->assertNull($rows[0]['before_json']);
    }

    public function testUpdateIsAuditedWithChangedFields(): void
    {
        $headers = $this->authHeaders(['products:*']);
        $id      = $this->createProduct($headers, 'Old');

        $this->withHeaders($headers)->withBodyFormat('json')->patch("api/v1/products/{$id}", ['name' => 'New']);

        $rows = (new AuditLogModel())->where('action', 'update')->where('record_id', (string) $id)->findAll();
        $this->assertNotEmpty($rows);
        $this->assertContains('name', json_decode((string) $rows[0]['changed_json'], true));
    }

    public function testAuditEndpointRequiresAuditScope(): void
    {
        $this->withHeaders($this->authHeaders(['products:read']))->get('api/v1/_audit')->assertStatus(403);
    }

    public function testDeepOffsetIsRefusedAndSteersToSinceId(): void
    {
        // The audit log only grows, so an uncapped deep OFFSET is an amplification DoS.
        // Past MAX_OFFSET (100000) the request is refused with a 400 pointing at sinceId;
        // sinceId itself (keyset) is never capped.
        $headers = $this->authHeaders(['audit:read']);

        $result = $this->withHeaders($headers)->get('api/v1/_audit?perPage=100&page=1002'); // offset 100100
        $result->assertStatus(400);
        $this->assertStringContainsStringIgnoringCase('sinceId', json_decode((string) $result->response()->getBody(), true)['detail']);

        // A normal page and the intended sinceId usage (small page) are unaffected.
        $this->withHeaders($headers)->get('api/v1/_audit?perPage=100&page=2')->assertStatus(200);
        $this->withHeaders($headers)->get('api/v1/_audit?sinceId=0&perPage=100&page=1')->assertStatus(200);

        // The cap applies even with sinceId — a deep OFFSET still scans regardless.
        $this->withHeaders($headers)->get('api/v1/_audit?sinceId=0&perPage=100&page=1002')->assertStatus(400);
    }

    public function testAuditEndpointListsEntries(): void
    {
        $headers = $this->authHeaders(['products:*', 'audit:read']);
        $this->createProduct($headers);

        $json = json_decode((string) $this->withHeaders($headers)->get('api/v1/_audit')->response()->getBody(), true);

        $this->assertNotEmpty($json['data']);
        $this->assertSame('create', $json['data'][0]['action']);
    }

    public function testSinceIdReturnsOnlyNewerEntriesOldestFirst(): void
    {
        $headers = $this->authHeaders(['products:*', 'audit:read']);

        $this->createProduct($headers);
        $this->createProduct($headers);
        $this->createProduct($headers);

        // Baseline is newest-first; use the second entry's id as a cursor.
        $all = json_decode((string) $this->withHeaders($headers)->get('api/v1/_audit')->response()->getBody(), true)['data'];
        $this->assertGreaterThanOrEqual(3, count($all));
        $cursor = (int) $all[1]['id'];

        $inc = json_decode((string) $this->withHeaders($headers)->get("api/v1/_audit?sinceId={$cursor}")->response()->getBody(), true)['data'];

        // Only entries strictly after the cursor, ascending (oldest-first).
        $ids = array_column($inc, 'id');
        $this->assertNotEmpty($ids);
        foreach ($ids as $id) {
            $this->assertGreaterThan($cursor, $id);
        }
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids);
    }

    public function testSinceIdForcesAscendingEvenWithAConflictingSort(): void
    {
        // CDC safety: a client polling `?sinceId=<max id I've seen>` advances the
        // cursor to the largest id on the page, so it MUST receive rows oldest-first
        // — otherwise, whenever more than one page of changes accrues between polls,
        // it takes the newest page's max id and silently skips the older unfetched
        // rows (lost n8n events). sinceId therefore pins ascending id order and must
        // ignore a conflicting descending `sort`. Regression guard for that override.
        $headers = $this->authHeaders(['products:*', 'audit:read']);

        $this->createProduct($headers);
        $this->createProduct($headers);
        $this->createProduct($headers);
        $this->createProduct($headers);

        $all = json_decode((string) $this->withHeaders($headers)->get('api/v1/_audit')->response()->getBody(), true)['data'];
        $this->assertGreaterThanOrEqual(4, count($all));
        $cursor = (int) $all[2]['id']; // entries remain on both sides of this

        // Deliberately request a DESCENDING sort alongside sinceId; it must be overridden.
        $inc = json_decode((string) $this->withHeaders($headers)->get("api/v1/_audit?sinceId={$cursor}&sort=-id")->response()->getBody(), true)['data'];
        $ids = array_column($inc, 'id');

        $this->assertNotEmpty($ids);
        foreach ($ids as $id) {
            $this->assertGreaterThan($cursor, $id);
        }
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids, 'sinceId must force ascending id order even when sort=-id is passed (CDC completeness)');
    }

    public function testSinceIdBeyondTheLatestReturnsEmpty(): void
    {
        $headers = $this->authHeaders(['products:*', 'audit:read']);
        $this->createProduct($headers);

        $json = json_decode((string) $this->withHeaders($headers)->get('api/v1/_audit?sinceId=999999999')->response()->getBody(), true);
        $this->assertSame([], $json['data']);
    }

    public function testInvalidSinceIdReturns400(): void
    {
        $this->withHeaders($this->authHeaders(['audit:read']))->get('api/v1/_audit?sinceId=abc')->assertStatus(400);
    }

    public function testAuditTimestampsAreIso8601(): void
    {
        $headers = $this->authHeaders(['products:*', 'audit:read']);
        $this->createProduct($headers);

        $entry = json_decode((string) $this->withHeaders($headers)->get('api/v1/_audit')->response()->getBody(), true)['data'][0];
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $entry['created_at']);
    }

    public function testAuditSnapshotsAreCastLikeTheLiveApi(): void
    {
        // The `after` snapshot is what an n8n change-feed consumer reads; it must be
        // the same typed, ISO-8601-Z shape as a live GET — not raw MySQLi strings and
        // a bare `Y-m-d H:i:s` timestamp — so both surfaces parse identically. Use a
        // fractional price so the float survives the JSON round-trip (a whole float
        // re-decodes to int).
        $headers = $this->authHeaders(['products:*', 'audit:read']);
        $id      = (string) json_decode((string) $this->withHeaders($headers)->withBodyFormat('json')
            ->post('api/v1/products', ['sku' => 'SKU-' . uniqid(), 'name' => 'N', 'price' => '2.50'])
            ->response()->getBody(), true)['data']['id'];

        $entry = json_decode((string) $this->withHeaders($headers)->get("api/v1/_audit?resource=products&record_id={$id}")->response()->getBody(), true)['data'][0];
        $after = $entry['after'];

        $this->assertIsInt($after['id']);                    // int, not "73"
        $this->assertIsFloat($after['price']);               // float 2.5, not "2.50"
        $this->assertSame(2.5, $after['price']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $after['created_at']);
    }
}

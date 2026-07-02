<?php

declare(strict_types=1);

use Config\Database;
use Tests\Support\FeatureTestCase;

/**
 * The engine is documented as "generic over primaryKey" — a resource may be keyed on
 * a natural string key (a code/slug/email), not an auto-increment int. This exercises
 * FULL CRUD through the HTTP layer for such a resource (WebhookTest only proved the
 * webhook carries the code). Registered via config('Resources') (the registry merges
 * it) with a real table created for the test.
 *
 * @internal
 */
final class StringPrimaryKeyTest extends FeatureTestCase
{
    private array $auth;

    protected function setUp(): void
    {
        parent::setUp();

        $forge = Database::forge();
        $forge->addField([
            'code'       => ['type' => 'VARCHAR', 'constraint' => 64],
            'label'      => ['type' => 'VARCHAR', 'constraint' => 200],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $forge->addKey('code', true);
        $forge->createTable('coupon', true);

        config('Resources')->resources['coupon'] = [
            'table'      => 'coupon',
            'primaryKey' => 'code',
            'fillable'   => ['code', 'label'],
            'hidden'     => [],
            'rules'      => [
                'create' => ['code' => 'required|max_length[64]', 'label' => 'required|max_length[200]'],
                'update' => ['label' => 'permit_empty|max_length[200]'],
            ],
            'sortable'    => ['code', 'created_at'],
            'filterable'  => ['code'],
            'defaultSort' => '-created_at',
            'perPage'     => ['default' => 25, 'max' => 100],
            'timestamps'  => true,
            'casts'       => ['created_at' => 'datetime', 'updated_at' => 'datetime'],
            'upsertKey'   => 'code',
        ];

        $this->auth = $this->authHeaders(['coupon:*']);
    }

    protected function tearDown(): void
    {
        unset(config('Resources')->resources['coupon']);
        Database::forge()->dropTable('coupon', true);
        parent::tearDown();
    }


    public function testCursorPaginationWalksEveryRowOnceForAStringPk(): void
    {
        // Keyset pagination iterates by the primary key: for a string PK a lexicographic
        // `WHERE code > <last>`. It must walk every row exactly once (no repeats/gaps) and
        // encode the code — not an int — in the opaque cursor.
        $codes = ['C-01', 'C-02', 'C-03', 'C-04', 'C-05'];
        foreach ($codes as $c) {
            $this->withHeaders($this->auth)->withBodyFormat('json')->post('api/v1/coupon', ['code' => $c, 'label' => 'x']);
        }

        $seen   = [];
        $cursor = '';
        do {
            $json = json_decode((string) $this->withHeaders($this->auth)
                ->get("api/v1/coupon?cursor={$cursor}&perPage=2")->response()->getBody(), true);
            foreach ($json['data'] as $row) {
                $seen[] = $row['code'];
            }
            $cursor = (string) ($json['meta']['pagination']['nextCursor'] ?? '');
        } while ($json['meta']['pagination']['hasMore'] === true);

        sort($seen);
        $this->assertSame($codes, $seen); // every code exactly once, in key order
    }

    public function testBulkCreateAndUpsertOnAStringPk(): void
    {
        // Bulk create — each item resolves by its own real key (uses the same insert()
        // path fixed for single create).
        $bulk = $this->withHeaders($this->auth)->withBodyFormat('json')->post('api/v1/coupon', [
            ['code' => 'B-1', 'label' => 'one'],
            ['code' => 'B-2', 'label' => 'two'],
        ]);
        $bulk->assertStatus(201);
        $this->assertSame(['B-1', 'B-2'], array_column(json_decode((string) $bulk->response()->getBody(), true)['data'], 'code'));

        // Upsert keyed on the string PK: create branch, then update branch on the same key.
        $create = $this->withHeaders($this->auth)->withBodyFormat('json')->put('api/v1/coupon', ['code' => 'U-1', 'label' => 'orig']);
        $create->assertStatus(201);
        $update = $this->withHeaders($this->auth)->withBodyFormat('json')->put('api/v1/coupon', ['code' => 'U-1', 'label' => 'changed']);
        $update->assertStatus(200);
        $this->assertSame('changed', json_decode((string) $this->withHeaders($this->auth)
            ->get('api/v1/coupon/U-1')->response()->getBody(), true)['data']['label']);
    }

    public function testArchiveAndRestoreOnAStringPk(): void
    {
        // Archival delete records record_id = the code; restore re-inserts by the string
        // key. Exercises the recycle-bin path (which resolves the row by primaryKey) for a
        // non-int key end to end.
        $auth = $this->authHeaders(['coupon:*', 'archive:read', 'archive:write']);

        $this->withHeaders($auth)->withBodyFormat('json')->post('api/v1/coupon', ['code' => 'ARC-1', 'label' => 'x']);
        $this->withHeaders($auth)->delete('api/v1/coupon/ARC-1')->assertStatus(204);
        $this->withHeaders($auth)->get('api/v1/coupon/ARC-1')->assertStatus(404);

        $archive = json_decode((string) $this->withHeaders($auth)->get('api/v1/_archive?resource=coupon')->response()->getBody(), true)['data'];
        $row     = array_values(array_filter($archive, static fn (array $r): bool => (string) $r['record_id'] === 'ARC-1'))[0];
        $this->assertSame('ARC-1', $row['record_id']); // the code, not a null/int id

        $this->withHeaders($auth)->post("api/v1/_archive/{$row['id']}/restore")->assertStatus(200);
        $this->withHeaders($auth)->get('api/v1/coupon/ARC-1')->assertStatus(200); // back
    }

    public function testStringPkCreateWithDigitPrefixedCodeAndMultipleRows(): void
    {
        // 'SAVE20' coerces to int 0, so find(0) matches it by accident. A digit-prefixed
        // code coerces to non-zero, and multiple rows make find(0) ambiguous — exposing
        // whether create truly resolves the just-created row by its real PK.
        $this->withHeaders($this->auth)->withBodyFormat('json')->post('api/v1/coupon', ['code' => 'AAA', 'label' => 'first']);
        $create = $this->withHeaders($this->auth)->withBodyFormat('json')
            ->post('api/v1/coupon', ['code' => '7UP', 'label' => 'seven up']);
        $create->assertStatus(201);
        $body = json_decode((string) $create->response()->getBody(), true)['data'];
        $this->assertSame('7UP', $body['code'] ?? null, 'create must echo the just-created row (7UP), not empty/wrong');
        $this->assertSame('seven up', $body['label'] ?? null);
    }

    public function testFullCrudOnAStringPrimaryKeyResource(): void
    {
        // CREATE — the client supplies the PK; the response must echo the real row
        // (code + label), not an empty row found by a bogus id=0.
        $create = $this->withHeaders($this->auth)->withBodyFormat('json')
            ->post('api/v1/coupon', ['code' => 'SAVE20', 'label' => '20 percent off']);
        $create->assertStatus(201);
        $body = json_decode((string) $create->response()->getBody(), true)['data'];
        $this->assertSame('SAVE20', $body['code']);
        $this->assertSame('20 percent off', $body['label']);

        // SHOW by the string key.
        $this->withHeaders($this->auth)->get('api/v1/coupon/SAVE20')->assertStatus(200);

        // UPDATE by the string key.
        $update = $this->withHeaders($this->auth)->withBodyFormat('json')
            ->patch('api/v1/coupon/SAVE20', ['label' => 'updated']);
        $update->assertStatus(200);
        $this->assertSame('updated', json_decode((string) $update->response()->getBody(), true)['data']['label']);

        // LIST returns it.
        $list = json_decode((string) $this->withHeaders($this->auth)->get('api/v1/coupon')->response()->getBody(), true);
        $this->assertSame('SAVE20', $list['data'][0]['code']);

        // DELETE by the string key, then it is gone.
        $this->withHeaders($this->auth)->delete('api/v1/coupon/SAVE20')->assertStatus(204);
        $this->withHeaders($this->auth)->get('api/v1/coupon/SAVE20')->assertStatus(404);
    }
}

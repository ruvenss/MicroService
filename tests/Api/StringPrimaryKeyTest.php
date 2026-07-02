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
        ];

        $this->auth = $this->authHeaders(['coupon:*']);
    }

    protected function tearDown(): void
    {
        unset(config('Resources')->resources['coupon']);
        Database::forge()->dropTable('coupon', true);
        parent::tearDown();
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

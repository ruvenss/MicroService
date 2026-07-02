<?php

declare(strict_types=1);

use Config\Database;
use Tests\Support\FeatureTestCase;

/**
 * A unique value that passes `is_unique` validation can still collide at the DB index
 * under concurrency (two requests race between the check and the insert). The loser's
 * insert must map to a clean 409 Conflict, not the neutral 500 a rolled-back
 * transaction yields — n8n treats them very differently.
 *
 * Reproduced deterministically with a resource that has a DB UNIQUE index but NO
 * is_unique rule: validation passes, so the insert reaches — and trips — the index,
 * exactly as the losing side of a real race does.
 *
 * @internal
 */
final class UniqueRaceTest extends FeatureTestCase
{
    /** @var array<string, string> */
    private array $auth;

    protected function setUp(): void
    {
        parent::setUp();

        $forge = Database::forge();
        $forge->addField([
            'id'         => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'code'       => ['type' => 'VARCHAR', 'constraint' => 64],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $forge->addPrimaryKey('id');
        $forge->addUniqueKey('code'); // the DB guard — but deliberately NO is_unique rule below
        $forge->createTable('ticket', true);

        config('Resources')->resources['ticket'] = [
            'table'      => 'ticket',
            'primaryKey' => 'id',
            'fillable'   => ['code'],
            'hidden'     => [],
            'rules'      => ['create' => ['code' => 'required'], 'update' => []],
            'sortable'   => ['code'],
            'filterable' => ['code'],
            'perPage'    => ['default' => 25, 'max' => 100],
            'timestamps' => true,
            'casts'      => ['id' => 'int'],
        ];

        $this->auth = $this->authHeaders(['ticket:*']);
    }

    protected function tearDown(): void
    {
        unset(config('Resources')->resources['ticket']);
        Database::forge()->dropTable('ticket', true);
        parent::tearDown();
    }

    public function testDbUniqueCollisionOnCreateIsA409NotA500(): void
    {
        $this->withHeaders($this->auth)->withBodyFormat('json')->post('api/v1/ticket', ['code' => 'T-1'])->assertStatus(201);

        $dup = $this->withHeaders($this->auth)->withBodyFormat('json')->post('api/v1/ticket', ['code' => 'T-1']);
        $dup->assertStatus(409);
        $this->assertStringContainsString('conflicting unique value', (string) $dup->response()->getBody());

        // Exactly one row — the loser wrote nothing (transaction rolled back cleanly).
        $rows = json_decode((string) $this->withHeaders($this->auth)->get('api/v1/ticket?filter[code]=T-1')->response()->getBody(), true)['data'];
        $this->assertCount(1, $rows);

        // The connection is still usable after the rolled-back conflict (a later write works).
        $this->withHeaders($this->auth)->withBodyFormat('json')->post('api/v1/ticket', ['code' => 'T-2'])->assertStatus(201);
    }
}

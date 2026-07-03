<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Idempotency-Key store: lets clients (e.g. n8n) safely retry unsafe writes.
 * The first response is recorded; a retry with the same key replays it instead
 * of re-executing. Unique per (api key, key).
 */
final class CreateIdempotencyKeys extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'              => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'api_key_id'      => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'idem_key'        => ['type' => 'VARCHAR', 'constraint' => 255],
            'method'          => ['type' => 'VARCHAR', 'constraint' => 8],
            'path'            => ['type' => 'VARCHAR', 'constraint' => 255],
            'request_hash'    => ['type' => 'CHAR', 'constraint' => 64],
            'response_status' => ['type' => 'SMALLINT', 'unsigned' => true],
            'response_type'   => ['type' => 'VARCHAR', 'constraint' => 100],
            'response_body'   => ['type' => 'TEXT', 'null' => true],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
            'expires_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['api_key_id', 'idem_key']);
        $this->forge->addKey('expires_at');
        $this->forge->createTable('idempotency_keys', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('idempotency_keys', true);
    }
}

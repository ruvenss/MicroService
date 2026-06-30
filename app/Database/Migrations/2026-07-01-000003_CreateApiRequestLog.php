<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Append-only access/usage log: one row per request for audit and per-key usage
 * accounting (see docs/ARCHITECTURE.md §7.3, §13).
 */
final class CreateApiRequestLog extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'api_key_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'request_id' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'method'     => ['type' => 'VARCHAR', 'constraint' => 8],
            'path'       => ['type' => 'VARCHAR', 'constraint' => 255],
            'resource'   => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'action'     => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
            'status'     => ['type' => 'SMALLINT', 'unsigned' => true],
            'latency_ms' => ['type' => 'INT', 'unsigned' => true],
            'ip'         => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['api_key_id', 'created_at']);
        $this->forge->createTable('api_request_log', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('api_request_log', true);
    }
}

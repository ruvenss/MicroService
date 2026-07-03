<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Auditing + archival-delete tables (see docs/ARCHITECTURE.md §13, §14).
 *
 * - audit_log: one row per state-changing operation with before/after snapshots.
 * - archived_records: the recycle bin — deleted rows are moved here, restorable.
 */
final class CreateAuditAndArchive extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'           => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'request_id'   => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'api_key_id'   => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'action'       => ['type' => 'VARCHAR', 'constraint' => 24],
            'resource'     => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'record_id'    => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'before_json'  => ['type' => 'JSON', 'null' => true],
            'after_json'   => ['type' => 'JSON', 'null' => true],
            'changed_json' => ['type' => 'JSON', 'null' => true],
            'ip'           => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['resource', 'record_id']);
        $this->forge->addKey(['api_key_id', 'created_at']);
        $this->forge->createTable('audit_log', true);

        $this->forge->addField([
            'id'           => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'resource'     => ['type' => 'VARCHAR', 'constraint' => 64],
            'source_table' => ['type' => 'VARCHAR', 'constraint' => 64],
            'record_id'    => ['type' => 'VARCHAR', 'constraint' => 64],
            'payload_json' => ['type' => 'JSON'],
            'deleted_by'   => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'request_id'   => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'deleted_at'   => ['type' => 'DATETIME', 'null' => true],
            'restored_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['resource', 'record_id']);
        $this->forge->addKey('deleted_at');
        $this->forge->createTable('archived_records', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('archived_records', true);
        $this->forge->dropTable('audit_log', true);
    }
}

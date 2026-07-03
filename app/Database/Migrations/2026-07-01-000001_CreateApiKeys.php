<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * API-key authentication tables (see docs/ARCHITECTURE.md §7).
 *
 * A service may hold many keys; each carries permission scopes. Only a SHA-256
 * hash of the secret is stored — the plaintext key is shown once at creation.
 */
final class CreateApiKeys extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'           => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'prefix'       => ['type' => 'VARCHAR', 'constraint' => 32],
            'secret_hash'  => ['type' => 'CHAR', 'constraint' => 64],
            'name'         => ['type' => 'VARCHAR', 'constraint' => 120],
            'status'       => ['type' => 'ENUM', 'constraint' => ['active', 'revoked'], 'default' => 'active'],
            'expires_at'   => ['type' => 'DATETIME', 'null' => true],
            'last_used_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('prefix');
        $this->forge->createTable('api_keys', true);

        $this->forge->addField([
            'id'         => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'api_key_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'scope'      => ['type' => 'VARCHAR', 'constraint' => 120],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['api_key_id', 'scope']);
        $this->forge->addForeignKey('api_key_id', 'api_keys', 'id', '', 'CASCADE');
        $this->forge->createTable('api_key_scopes', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('api_key_scopes', true);
        $this->forge->dropTable('api_keys', true);
    }
}

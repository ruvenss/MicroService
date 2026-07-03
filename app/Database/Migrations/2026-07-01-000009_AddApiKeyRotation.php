<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds a grace-window secret to api_keys so a key can be rotated without downtime:
 * `key:rotate` moves the current hash to `secret_hash_previous` (valid until
 * `previous_expires_at`) and installs a fresh `secret_hash`. During the window
 * both secrets authenticate, so an n8n workflow can migrate to the new key before
 * the old one stops working.
 */
final class AddApiKeyRotation extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('api_keys', [
            'secret_hash_previous' => ['type' => 'CHAR', 'constraint' => 64, 'null' => true, 'after' => 'secret_hash'],
            'previous_expires_at'  => ['type' => 'DATETIME', 'null' => true, 'after' => 'secret_hash_previous'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('api_keys', ['secret_hash_previous', 'previous_expires_at']);
    }
}

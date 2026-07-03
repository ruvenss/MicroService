<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Per-key requests-per-minute override (NULL = use the global default).
 */
final class AddRateLimitToApiKeys extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('api_keys', [
            'rate_limit' => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'status'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('api_keys', 'rate_limit');
    }
}

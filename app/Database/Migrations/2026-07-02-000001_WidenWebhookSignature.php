<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Widens webhook_outbox.signature from VARCHAR(64) to VARCHAR(255).
 *
 * The stored digest is bare hex (64), but the delivery header is algorithm-tagged
 * (`sha256=<hex>`, the GitHub/Stripe/Svix convention). The extra headroom keeps the
 * column forward-compatible with a longer or tagged digest should the scheme ever
 * change, at no storage cost (VARCHAR stores only the actual length).
 */
final class WidenWebhookSignature extends Migration
{
    public function up(): void
    {
        $this->forge->modifyColumn('webhook_outbox', [
            'signature' => ['type' => 'VARCHAR', 'constraint' => 255],
        ]);
    }

    public function down(): void
    {
        if (! $this->db->tableExists('webhook_outbox')) {
            return; // nothing to narrow if the table was already dropped
        }
        // Clear any value that wouldn't fit the narrower column before shrinking, so
        // the reversal can't fail on truncation. Raw SQL needs the table prefix applied
        // by hand (the query builder would need a WHERE workaround for the LENGTH filter).
        $table = $this->db->DBPrefix . 'webhook_outbox';
        $this->db->query("UPDATE `{$table}` SET signature = '' WHERE LENGTH(signature) > 64");
        $this->forge->modifyColumn('webhook_outbox', [
            'signature' => ['type' => 'VARCHAR', 'constraint' => 64],
        ]);
    }
}

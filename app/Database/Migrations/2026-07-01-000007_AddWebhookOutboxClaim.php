<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds a claim to webhook_outbox so concurrent `webhooks:dispatch` runs can never
 * deliver the same row twice to n8n. A dispatcher atomically stamps a batch with
 * its own `claim_token` (flipping status to `dispatching`) before POSTing; a crash
 * leaves the claim stale and `claimed_at` lets a later run reclaim it.
 */
final class AddWebhookOutboxClaim extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('webhook_outbox', [
            'claim_token' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true, 'after' => 'attempts'],
            'claimed_at'  => ['type' => 'DATETIME', 'null' => true, 'after' => 'claim_token'],
        ]);
        // Speeds up the claim UPDATE's scan for deliverable / stale-claimed rows.
        $this->forge->addKey('claimed_at');
        $this->forge->processIndexes('webhook_outbox');
    }

    public function down(): void
    {
        $this->forge->dropColumn('webhook_outbox', ['claim_token', 'claimed_at']);
    }
}

<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds `next_attempt_at` to webhook_outbox for exponential-backoff retries. A
 * failed delivery sets this to a future time; the claim query skips a failed row
 * until it is due, so a flapping/unavailable n8n is not hammered and the attempt
 * budget is spread over time instead of burned in seconds. NULL = eligible now
 * (all newly enqueued rows).
 */
final class AddWebhookOutboxBackoff extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('webhook_outbox', [
            'next_attempt_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'claimed_at'],
        ]);
        // The claim UPDATE filters on due-ness alongside status.
        $this->forge->addKey('next_attempt_at');
        $this->forge->processIndexes('webhook_outbox');
    }

    public function down(): void
    {
        $this->forge->dropColumn('webhook_outbox', 'next_attempt_at');
    }
}

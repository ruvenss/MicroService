<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Outbox for outbound webhooks (e.g. notifying n8n of resource changes). Events
 * are enqueued here on mutation and delivered by `spark webhooks:dispatch`, so
 * the API request never blocks on the remote endpoint and delivery is retriable.
 */
final class CreateWebhookOutbox extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'           => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'event'        => ['type' => 'VARCHAR', 'constraint' => 96],
            'resource'     => ['type' => 'VARCHAR', 'constraint' => 64],
            'record_id'    => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'target_url'   => ['type' => 'VARCHAR', 'constraint' => 500],
            'payload_json' => ['type' => 'TEXT'],
            'signature'    => ['type' => 'VARCHAR', 'constraint' => 64],
            'status'       => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'pending'],
            'attempts'     => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'last_error'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
            'delivered_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['status', 'attempts']);
        $this->forge->createTable('webhook_outbox', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('webhook_outbox', true);
    }
}

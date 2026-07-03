<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The n8n per-resource incremental sync polls `_audit?resource=X&sinceId=N`, i.e.
 * `WHERE resource = ? AND id > ? ORDER BY id ASC`. The existing (resource, record_id)
 * index serves the resource filter but not the `id` order, so that query filesorts.
 * A (resource, id) index turns it into an index range scan — no filesort — so the
 * change feed stays fast as the audit trail grows.
 */
final class AddAuditResourceIdIndex extends Migration
{
    public function up(): void
    {
        $this->forge->addKey(['resource', 'id'], false, false, 'audit_log_resource_id');
        $this->forge->processIndexes('audit_log');
    }

    public function down(): void
    {
        $this->forge->dropKey('audit_log', 'audit_log_resource_id', false);
    }
}

<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Widens record_id from VARCHAR(64) to VARCHAR(255) in audit_log, archived_records,
 * and webhook_outbox.
 *
 * record_id holds a resource's primary-key value. The engine is generic over
 * primaryKey (a resource may be keyed on a natural string key — a code, slug, or
 * email — not an int id), and such a value can exceed 64 chars. Under MySQL's
 * STRICT_TRANS_TABLES an over-length insert is REJECTED, so a long-key record would
 * 500 on create/delete (the transactional audit/archive write) and silently drop its
 * webhook (the fail-open enqueue). Unlike the metadata `path`/`method` columns,
 * record_id is an identifier used for restore/lookup/filtering, so it must be WIDENED
 * to fit, not truncated. 255 covers realistic natural keys (e.g. an email is ≤254) at
 * no storage cost — VARCHAR stores only the actual length.
 */
final class WidenRecordId extends Migration
{
    /** @var list<string> */
    private const NULLABLE = ['audit_log', 'webhook_outbox'];

    public function up(): void
    {
        foreach (['audit_log', 'archived_records', 'webhook_outbox'] as $table) {
            $this->forge->modifyColumn($table, [
                'record_id' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => in_array($table, self::NULLABLE, true)],
            ]);
        }
    }

    public function down(): void
    {
        foreach (['audit_log', 'archived_records', 'webhook_outbox'] as $table) {
            if (! $this->db->tableExists($table)) {
                continue;
            }
            // Trim any value that wouldn't fit the narrower column before shrinking, so the
            // reversal can't die on truncation (migrate:refresh runs down() on populated
            // tables mid-suite). Raw SQL needs the prefix applied by hand.
            $prefixed = $this->db->DBPrefix . $table;
            $this->db->query("UPDATE `{$prefixed}` SET record_id = LEFT(record_id, 64) WHERE LENGTH(record_id) > 64");
            $this->forge->modifyColumn($table, [
                'record_id' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => in_array($table, self::NULLABLE, true)],
            ]);
        }
    }
}

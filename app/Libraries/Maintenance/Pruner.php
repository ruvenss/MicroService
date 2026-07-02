<?php

declare(strict_types=1);

namespace App\Libraries\Maintenance;

use Config\Retention;

/**
 * Purges rows from the transient operational tables past their retention window,
 * so a long-running service never accumulates unbounded log/idempotency data.
 *
 * Only truly disposable data is touched:
 *   - idempotency_keys : rows whose `expires_at` has already passed (spent).
 *   - api_request_log  : access-log rows older than the access-log window.
 *   - webhook_outbox   : rows already `delivered` older than the delivered window, AND
 *                        dead-lettered rows (failed, attempts exhausted) older than the
 *                        longer dead-letter window (kept for replay, but not forever).
 *
 * `audit_log` and `archived_records` are intentionally never pruned here.
 */
final class Pruner
{
    /**
     * @return array{idempotency_keys: int, api_request_log: int, webhook_outbox: int, webhook_outbox_deadletter: int}
     */
    public static function run(Retention $config, bool $dryRun = false): array
    {
        $maxAttempts = config('Webhooks')->maxAttempts;

        return [
            'idempotency_keys'          => self::pruneExpiredIdempotencyKeys($dryRun),
            'api_request_log'           => self::pruneOlderThan('api_request_log', 'created_at', $config->accessLogDays, $dryRun),
            'webhook_outbox'            => self::pruneDeliveredWebhooks($config->deliveredWebhookDays, $dryRun),
            'webhook_outbox_deadletter' => self::pruneDeadLetteredWebhooks($config->deadLetteredWebhookDays, $maxAttempts, $dryRun),
        ];
    }

    public static function pruneExpiredIdempotencyKeys(bool $dryRun = false): int
    {
        $now = date('Y-m-d H:i:s');
        $db  = db_connect();

        $count = $db->table('idempotency_keys')->where('expires_at <', $now)->countAllResults();
        if (! $dryRun && $count > 0) {
            $db->table('idempotency_keys')->where('expires_at <', $now)->delete();
        }

        return $count;
    }

    public static function pruneDeliveredWebhooks(int $days, bool $dryRun = false): int
    {
        $cutoff = self::cutoff($days);
        $db     = db_connect();

        $count = $db->table('webhook_outbox')
            ->where('status', 'delivered')
            ->where('delivered_at <', $cutoff)
            ->countAllResults();
        if (! $dryRun && $count > 0) {
            $db->table('webhook_outbox')
                ->where('status', 'delivered')
                ->where('delivered_at <', $cutoff)
                ->delete();
        }

        return $count;
    }

    /**
     * Prune dead-lettered webhooks (status=failed with attempts exhausted) enqueued more
     * than `$days` ago. Rows still inside the retry pipeline (attempts < maxAttempts) are
     * left alone — only the exhausted, stale ones are removed, so `webhooks:retry` keeps
     * its replay window while the table stays bounded.
     */
    public static function pruneDeadLetteredWebhooks(int $days, int $maxAttempts, bool $dryRun = false): int
    {
        $cutoff = self::cutoff($days);
        $db     = db_connect();
        $where  = static fn () => $db->table('webhook_outbox')
            ->where('status', 'failed')
            ->where('attempts >=', $maxAttempts)
            ->where('created_at <', $cutoff);

        $count = $where()->countAllResults();
        if (! $dryRun && $count > 0) {
            $where()->delete();
        }

        return $count;
    }

    private static function pruneOlderThan(string $table, string $column, int $days, bool $dryRun): int
    {
        $cutoff = self::cutoff($days);
        $db     = db_connect();

        $count = $db->table($table)->where("{$column} <", $cutoff)->countAllResults();
        if (! $dryRun && $count > 0) {
            $db->table($table)->where("{$column} <", $cutoff)->delete();
        }

        return $count;
    }

    /** Cutoff timestamp: rows older than `now - $days` are eligible. */
    private static function cutoff(int $days): string
    {
        return date('Y-m-d H:i:s', time() - max(0, $days) * 86400);
    }
}

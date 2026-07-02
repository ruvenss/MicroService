<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Data-retention windows for the transient operational tables, applied by
 * `php spark maintenance:prune` (run on a schedule). Prevents unbounded growth
 * that would eventually slow queries and fill disk on a long-running service.
 *
 * Deliberately NOT covered here: `audit_log` (the compliance mutation trail) and
 * `archived_records` (the restorable recycle bin) are never auto-pruned — losing
 * them would break auditing / restore guarantees.
 *
 * A window of **0 (or less) DISABLES pruning for that table — keep forever**. This is
 * the safe reading of `RETENTION_*_DAYS=0`, a negative, or a non-numeric env like
 * `never` (which `(int)` casts to 0): "keep everything", never "delete everything"
 * (a 0-day cutoff is *now*, which without the guard would wipe the whole table).
 */
class Retention extends BaseConfig
{
    /** Days to keep access-log rows (`api_request_log`). 0 or less = keep forever. */
    public int $accessLogDays = 30;

    /** Days to keep already-delivered webhook rows (`webhook_outbox` status=delivered). */
    public int $deliveredWebhookDays = 7;

    /**
     * Days to keep **dead-lettered** webhook rows (status=failed, attempts exhausted).
     * These are kept longer than delivered ones so `webhooks:retry` can replay them
     * after an n8n outage — but not forever, or they grow unbounded. A month-old
     * undelivered notification is stale; pruning it bounds the table.
     */
    public int $deadLetteredWebhookDays = 30;

    public function __construct()
    {
        parent::__construct();

        // Env overrides so operators can tune retention without a code change.
        $accessLog = env('RETENTION_ACCESS_LOG_DAYS');
        if ($accessLog !== null && $accessLog !== '') {
            $this->accessLogDays = (int) $accessLog;
        }

        $webhook = env('RETENTION_DELIVERED_WEBHOOK_DAYS');
        if ($webhook !== null && $webhook !== '') {
            $this->deliveredWebhookDays = (int) $webhook;
        }

        $deadLettered = env('RETENTION_DEADLETTERED_WEBHOOK_DAYS');
        if ($deadLettered !== null && $deadLettered !== '') {
            $this->deadLetteredWebhookDays = (int) $deadLettered;
        }
    }
}

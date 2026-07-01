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
 */
class Retention extends BaseConfig
{
    /** Days to keep access-log rows (`api_request_log`). */
    public int $accessLogDays = 30;

    /** Days to keep already-delivered webhook rows (`webhook_outbox` status=delivered). */
    public int $deliveredWebhookDays = 7;

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
    }
}

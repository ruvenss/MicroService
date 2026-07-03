<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Maintenance\Pruner;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Retention;

/**
 * Prunes the transient operational tables (expired idempotency keys, old access
 * logs, delivered webhooks) past their retention window. Run on a schedule so a
 * long-running service never accumulates unbounded data. `audit_log` and the
 * `archived_records` recycle bin are never touched.
 */
class MaintenancePrune extends BaseCommand
{
    protected $group       = 'Maintenance';
    protected $name        = 'maintenance:prune';
    protected $description = 'Purge expired/old rows from the transient operational tables (retention).';
    protected $usage       = 'maintenance:prune [--dry-run] [--access-log-days N] [--webhook-days N] [--deadlettered-webhook-days N]';
    protected $options     = [
        '--dry-run'                   => 'Report what would be deleted; change nothing.',
        '--access-log-days'           => 'Override api_request_log retention (days).',
        '--webhook-days'              => 'Override delivered webhook retention (days).',
        '--deadlettered-webhook-days' => 'Override dead-lettered (exhausted) webhook retention (days).',
    ];

    public function run(array $params)
    {
        // Options come via CLI::$options under spark, but via $params through the
        // test command() helper — read both so behaviour is identical either way.
        $isDryRun = array_key_exists('dry-run', $params) || array_key_exists('dry-run', CLI::getOptions());

        $config = new Retention();
        $accessDays = $params['access-log-days'] ?? CLI::getOption('access-log-days');
        if ($accessDays !== null) {
            $config->accessLogDays = (int) $accessDays;
        }
        $webhookDays = $params['webhook-days'] ?? CLI::getOption('webhook-days');
        if ($webhookDays !== null) {
            $config->deliveredWebhookDays = (int) $webhookDays;
        }
        $deadLetteredDays = $params['deadlettered-webhook-days'] ?? CLI::getOption('deadlettered-webhook-days');
        if ($deadLetteredDays !== null) {
            $config->deadLetteredWebhookDays = (int) $deadLetteredDays;
        }

        $counts = Pruner::run($config, $isDryRun);
        $total  = array_sum($counts);
        $verb   = $isDryRun ? 'Would prune' : 'Pruned';

        CLI::write("{$verb} {$total} row(s):", $total > 0 ? 'green' : 'yellow');
        foreach ($counts as $table => $count) {
            CLI::write("  {$table}: {$count}");
        }
        if ($isDryRun) {
            CLI::write('(dry run — nothing changed)', 'yellow');
        }

        return EXIT_SUCCESS;
    }
}

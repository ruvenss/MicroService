<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\WebhookDispatcher;
use App\Models\WebhookOutboxModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Replays the webhook dead-letter queue: resets rows that exhausted their retry
 * budget back to pending so `webhooks:dispatch` delivers them again — the
 * recovery step after n8n was down longer than the backoff schedule allowed.
 */
class WebhooksRetry extends BaseCommand
{
    protected $group       = 'Webhooks';
    protected $name        = 'webhooks:retry';
    protected $description = 'Requeue dead-lettered webhooks (attempts exhausted) for another delivery pass.';
    protected $usage       = 'webhooks:retry [--limit N] [--dry-run]';
    protected $options     = [
        '--limit'   => 'Max dead-lettered rows to requeue (default 100).',
        '--dry-run' => 'Only report how many are dead-lettered; change nothing.',
    ];

    public function run(array $params)
    {
        // Options arrive in CLI::$options under real spark, but in $params via the
        // test `command()` helper — read both so the command behaves the same way.
        $limit    = (int) ($params['limit'] ?? CLI::getOption('limit') ?? 100);
        $isDryRun = array_key_exists('dry-run', $params) || array_key_exists('dry-run', CLI::getOptions());

        if ($isDryRun) {
            $count = (new WebhookOutboxModel())->deadLetterCount(config('Webhooks')->maxAttempts);
            CLI::write("Dead-lettered webhooks: {$count} (dry run — nothing changed).", 'yellow');

            return EXIT_SUCCESS;
        }

        $result = WebhookDispatcher::retryDeadLettered($limit > 0 ? $limit : 100);

        if ($result['deadLettered'] === 0) {
            CLI::write('No dead-lettered webhooks to retry.', 'green');

            return EXIT_SUCCESS;
        }

        CLI::write(sprintf(
            'Requeued %d of %d dead-lettered webhook(s). Run `webhooks:dispatch` to deliver them.',
            $result['resurrected'],
            $result['deadLettered'],
        ), 'green');

        return EXIT_SUCCESS;
    }
}

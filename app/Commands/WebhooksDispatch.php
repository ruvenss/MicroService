<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\WebhookDispatcher;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Delivers queued outbound webhooks (to n8n). Run on a schedule (cron /
 * `/schedule`) so the API request thread never blocks on the remote endpoint.
 */
class WebhooksDispatch extends BaseCommand
{
    protected $group       = 'Webhooks';
    protected $name        = 'webhooks:dispatch';
    protected $description = 'Deliver pending outbound webhooks from the outbox (retries failures).';
    protected $usage       = 'webhooks:dispatch [--limit N]';
    protected $options     = ['--limit' => 'Max rows to process this run (default 100).'];

    public function run(array $params)
    {
        $limit  = (int) (CLI::getOption('limit') ?? 100);
        $result = WebhookDispatcher::dispatch($limit > 0 ? $limit : 100);

        CLI::write(sprintf(
            'Webhooks: processed %d, delivered %d, failed %d.',
            $result['processed'],
            $result['sent'],
            $result['failed'],
        ), $result['failed'] > 0 ? 'yellow' : 'green');
    }
}

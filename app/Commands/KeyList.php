<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\ApiKeyModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Lists API keys (never their secrets) with status and scopes.
 */
class KeyList extends BaseCommand
{
    protected $group       = 'API Keys';
    protected $name        = 'key:list';
    protected $description = 'List API keys with their status and scopes.';
    protected $usage       = 'key:list';

    public function run(array $params)
    {
        $model = new ApiKeyModel();
        $keys  = $model->orderBy('id', 'ASC')->findAll();

        if ($keys === []) {
            CLI::write('No API keys. Create one with: php spark key:create', 'yellow');

            return;
        }

        $rows = [];
        foreach ($keys as $key) {
            $rows[] = [
                $key['id'],
                $key['prefix'],
                $key['name'],
                $key['status'],
                implode(', ', $model->scopesFor((int) $key['id'])),
                $key['last_used_at'] ?? '—',
                $key['expires_at'] ?? '—',
            ];
        }

        CLI::table($rows, ['ID', 'Prefix', 'Name', 'Status', 'Scopes', 'Last used', 'Expires']);
    }
}

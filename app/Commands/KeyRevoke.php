<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\ApiKeyModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Revokes an API key by prefix (sets status = revoked). Revoked keys fail auth
 * immediately.
 */
class KeyRevoke extends BaseCommand
{
    protected $group       = 'API Keys';
    protected $name        = 'key:revoke';
    protected $description = 'Revoke an API key by its prefix.';
    protected $usage       = 'key:revoke <prefix>';
    protected $arguments   = ['prefix' => 'The public prefix of the key to revoke.'];

    public function run(array $params)
    {
        $prefix = $params[0] ?? CLI::prompt('Key prefix to revoke');

        $model = new ApiKeyModel();
        $key   = $model->where('prefix', $prefix)->first();

        if ($key === null) {
            CLI::error('No key found with prefix: ' . $prefix);

            return;
        }

        $model->update($key['id'], ['status' => 'revoked']);
        CLI::write('Revoked key id ' . $key['id'] . ' (' . $prefix . ').', 'green');
    }
}

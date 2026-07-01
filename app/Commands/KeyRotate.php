<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\ApiKeyModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Rotates an API key's secret without downtime: a fresh secret is installed and
 * the previous one keeps working for a grace window, so an n8n workflow can be
 * updated to the new key before the old one stops authenticating.
 */
class KeyRotate extends BaseCommand
{
    protected $group       = 'API Keys';
    protected $name        = 'key:rotate';
    protected $description = 'Rotate a key secret, keeping the old one valid for a grace window.';
    protected $usage       = 'key:rotate <prefix> [--grace-hours N]';
    protected $arguments   = ['prefix' => 'The public prefix of the key to rotate.'];
    protected $options     = ['--grace-hours' => 'Hours the old secret stays valid (default 24).'];

    public function run(array $params)
    {
        $prefix = $params[0] ?? CLI::prompt('Key prefix to rotate');
        $grace  = (int) ($params['grace-hours'] ?? CLI::getOption('grace-hours') ?? 24);

        $result = (new ApiKeyModel())->rotate((string) $prefix, $grace);
        if ($result === null) {
            CLI::error("No active key with prefix '{$prefix}'. Run `php spark key:list`.");

            return EXIT_ERROR;
        }

        $key = $result['prefix'] . '.' . $result['secret'];

        CLI::newLine();
        CLI::write('API key rotated.', 'green');
        CLI::write('  New key (shown once): ' . CLI::color($key, 'yellow'));
        CLI::write('  Header: Authorization: Bearer ' . $key);
        CLI::write('  Old secret still valid until: ' . $result['previous_expires_at'] . " (grace {$grace}h).");
        CLI::write('  Update the n8n credential before then.', 'yellow');
        CLI::newLine();

        return EXIT_SUCCESS;
    }
}

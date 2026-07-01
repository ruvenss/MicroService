<?php

declare(strict_types=1);

namespace App\Commands;

use App\Core\Plugin\PluginManager;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Lists every plugin found under plugins/ — enabled and disabled — with what it
 * contributes, so an operator can see what `plugin:enable`/`plugin:disable` act on.
 */
class PluginList extends BaseCommand
{
    protected $group       = 'Plugins';
    protected $name        = 'plugin:list';
    protected $description = 'List all plugins (enabled and disabled) and the resources they contribute.';

    public function run(array $params)
    {
        $catalog = PluginManager::catalog();

        if ($catalog === []) {
            CLI::write('No plugins found under plugins/.', 'yellow');

            return;
        }

        $rows = [];
        foreach ($catalog as $entry) {
            $rows[] = [
                $entry['name'] !== '' ? $entry['name'] : '?',
                $entry['version'],
                $entry['enabled'] ? CLI::color('enabled', 'green') : CLI::color('disabled', 'yellow'),
                implode(', ', $entry['resources']),
            ];
        }

        CLI::table($rows, ['Plugin', 'Version', 'Status', 'Resources']);
    }
}

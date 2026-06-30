<?php

declare(strict_types=1);

namespace App\Commands;

use App\Core\Plugin\PluginManager;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Lists discovered (enabled) plugins and what they contribute.
 */
class PluginList extends BaseCommand
{
    protected $group       = 'Plugins';
    protected $name        = 'plugin:list';
    protected $description = 'List enabled plugins and the resources they contribute.';

    public function run(array $params)
    {
        $manifests = PluginManager::instance()->manifests();

        if ($manifests === []) {
            CLI::write('No plugins found under plugins/.', 'yellow');

            return;
        }

        $rows = [];
        foreach ($manifests as $m) {
            $resources = $m['provides']['resources'] ?? [];
            $rows[]    = [
                $m['name'] ?? '?',
                $m['version'] ?? '?',
                $m['namespace'] ?? '?',
                is_array($resources) ? implode(', ', $resources) : '',
            ];
        }

        CLI::table($rows, ['Plugin', 'Version', 'Namespace', 'Resources']);
    }
}

<?php

declare(strict_types=1);

namespace App\Commands;

use App\Core\Plugin\PluginManager;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Enables a plugin by flipping `enabled: true` in its plugin.json — no manual
 * JSON editing. The change takes effect on the next boot; if the plugin ships
 * migrations run `spark migrate --all`, then regenerate docs.
 */
class PluginEnable extends BaseCommand
{
    protected $group       = 'Plugins';
    protected $name        = 'plugin:enable';
    protected $description = 'Enable a plugin (sets enabled=true in its plugin.json).';
    protected $usage       = 'plugin:enable <Vendor/Name>';
    protected $arguments   = ['Vendor/Name' => 'The plugin name from its manifest (e.g. Sample/Catalog).'];

    public function run(array $params)
    {
        // Required identifier — never CLI::prompt() (TypeErrors on a non-interactive
        // EOF, e.g. CI or a scripted `docker exec -T`); report a clean message instead.
        $name = (string) ($params[0] ?? '');
        if ($name === '') {
            CLI::error('Provide the plugin name: php spark plugin:enable Vendor/Name. Run `php spark plugin:list`.');

            return EXIT_ERROR;
        }

        $result = PluginManager::setEnabled($name, true);
        if ($result === null) {
            CLI::error("Plugin '{$name}' not found. Run `php spark plugin:list` to see available plugins.");

            return EXIT_ERROR;
        }

        CLI::write("Enabled {$result['name']} (v{$result['version']}).", 'green');
        if ($result['resources'] !== []) {
            CLI::write('  Contributes: ' . implode(', ', $result['resources']));
        }
        CLI::write('  Run `php spark migrate --all` if it ships migrations, then `php spark docs:generate`.', 'yellow');

        return EXIT_SUCCESS;
    }
}

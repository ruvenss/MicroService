<?php

declare(strict_types=1);

namespace App\Commands;

use App\Core\Plugin\PluginManager;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Disables a plugin by flipping `enabled: false` in its plugin.json — the sealed
 * core then skips it at boot and its resources/endpoints disappear from the
 * registry (and from regenerated docs). Data and migrations are left untouched.
 */
class PluginDisable extends BaseCommand
{
    protected $group       = 'Plugins';
    protected $name        = 'plugin:disable';
    protected $description = 'Disable a plugin (sets enabled=false in its plugin.json).';
    protected $usage       = 'plugin:disable <Vendor/Name>';
    protected $arguments   = ['Vendor/Name' => 'The plugin name from its manifest (e.g. Sample/Catalog).'];

    public function run(array $params)
    {
        $name = $params[0] ?? CLI::prompt('Plugin name (Vendor/Name)');

        $result = PluginManager::setEnabled((string) $name, false);
        if ($result === null) {
            CLI::error("Plugin '{$name}' not found. Run `php spark plugin:list` to see available plugins.");

            return EXIT_ERROR;
        }

        CLI::write("Disabled {$result['name']} (v{$result['version']}).", 'green');
        if ($result['resources'] !== []) {
            CLI::write('  Its resources are now unavailable: ' . implode(', ', $result['resources']));
        }
        CLI::write('  Regenerate docs (`php spark docs:generate`) so the API surface reflects the change.', 'yellow');

        return EXIT_SUCCESS;
    }
}

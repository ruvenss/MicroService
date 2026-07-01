<?php

declare(strict_types=1);

namespace App\Commands;

use App\Core\Plugin\PluginScaffolder;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use InvalidArgumentException;

/**
 * Scaffolds a new, self-contained plugin under plugins/<Vendor>/<Name>/.
 */
class MakePlugin extends BaseCommand
{
    protected $group       = 'Plugins';
    protected $name        = 'make:plugin';
    protected $description = 'Scaffold a new plugin (manifest, Plugin class, migration, README).';
    protected $usage       = 'make:plugin <Vendor/Name>';
    protected $arguments   = ['Vendor/Name' => 'StudlyCase vendor and plugin name, e.g. Acme/Billing.'];

    public function run(array $params)
    {
        $arg = $params[0] ?? CLI::prompt('Plugin (Vendor/Name)');

        try {
            $files = PluginScaffolder::files((string) $arg);
        } catch (InvalidArgumentException $e) {
            CLI::error($e->getMessage());

            return;
        }

        $root      = ROOTPATH . 'plugins/';
        $firstPath = array_key_first($files);
        $pluginDir = $root . dirname((string) $firstPath);

        if (is_dir($pluginDir)) {
            CLI::error('Plugin already exists: ' . $pluginDir);

            return;
        }

        foreach ($files as $relative => $contents) {
            $path = $root . $relative;
            $dir  = dirname($path);
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            file_put_contents($path, $contents);
            CLI::write('  created ' . $relative, 'green');
        }

        CLI::newLine();
        CLI::write('Plugin scaffolded. Next:', 'yellow');
        CLI::write('  php spark migrate --all      # create its table');
        CLI::write('  php spark docs:generate      # refresh OpenAPI / Postman / Markdown');
    }
}

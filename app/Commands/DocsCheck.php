<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Docs\DocsBundle;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Fails (exit 1) if the committed docs differ from what the registry would
 * generate — the CI drift gate. Run `php spark docs:generate` to fix.
 */
class DocsCheck extends BaseCommand
{
    protected $group       = 'Documentation';
    protected $name        = 'docs:check';
    protected $description = 'Fail if the generated docs (OpenAPI/Markdown/Postman) are out of date.';

    public function run(array $params)
    {
        $stale = [];

        foreach (DocsBundle::artifacts() as $path => $expected) {
            if (! is_file($path) || file_get_contents($path) !== $expected) {
                $stale[] = $path;
            }
        }

        if ($stale !== []) {
            CLI::error('Documentation is out of date. Run: php spark docs:generate');
            foreach ($stale as $path) {
                CLI::write('  stale: ' . str_replace(ROOTPATH, '', $path));
            }

            return EXIT_ERROR;
        }

        CLI::write('Documentation is in sync.', 'green');

        return EXIT_SUCCESS;
    }
}

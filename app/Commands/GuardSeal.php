<?php

declare(strict_types=1);

namespace App\Commands;

use App\Core\Seal\SealGuard;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * CI guard for the sealed core (ARCHITECTURE decision #10): fails if any file in
 * app/ couples to a concrete plugin. Run in the pipeline alongside stan/cs/docs.
 */
class GuardSeal extends BaseCommand
{
    protected $group       = 'Quality';
    protected $name        = 'guard:seal';
    protected $description = 'Fail if the core (app/) references a concrete plugin (sealed-core invariant).';
    protected $usage       = 'guard:seal';

    public function run(array $params)
    {
        $violations = SealGuard::violations(APPPATH);

        if ($violations === []) {
            CLI::write('Sealed core OK: app/ references no concrete plugin.', 'green');

            return EXIT_SUCCESS;
        }

        CLI::error('Sealed-core violation — the core must stay plugin-agnostic (build features in plugins/):');
        foreach ($violations as $v) {
            CLI::write('  ' . str_replace(APPPATH, 'app/', $v['file']) . ':' . $v['line'] . '  ' . $v['text']);
        }

        return EXIT_ERROR;
    }
}

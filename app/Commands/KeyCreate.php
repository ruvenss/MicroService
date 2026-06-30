<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\ApiKeyModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Mints an API key. The secret is generated, its hash stored, and the full key
 * printed exactly once — it cannot be recovered afterwards.
 */
class KeyCreate extends BaseCommand
{
    protected $group       = 'API Keys';
    protected $name        = 'key:create';
    protected $description = 'Create an API key and print the secret once.';
    protected $usage       = 'key:create [--name <name>] [--scopes <a:b,c:d>] [--expires <YYYY-MM-DD>]';
    protected $options     = [
        '--name'    => 'Human label for the key.',
        '--scopes'  => 'Comma-separated scopes, e.g. products:read,products:write (default *:read).',
        '--expires' => 'Optional expiry date (YYYY-MM-DD).',
    ];

    public function run(array $params)
    {
        $name      = CLI::getOption('name') ?? CLI::prompt('Key name', 'default');
        $scopesOpt = CLI::getOption('scopes') ?? CLI::prompt('Scopes (comma-separated)', '*:read');
        $expires   = CLI::getOption('expires');

        $prefix = bin2hex(random_bytes(6));
        $secret = bin2hex(random_bytes(24));

        $model = new ApiKeyModel();
        $id    = (int) $model->insert([
            'prefix'      => $prefix,
            'secret_hash' => hash('sha256', $secret),
            'name'        => (string) $name,
            'status'      => 'active',
            'expires_at'  => $expires !== null ? date('Y-m-d H:i:s', (int) strtotime((string) $expires)) : null,
        ], true);

        $scopes = array_values(array_filter(array_map('trim', explode(',', (string) $scopesOpt))));
        foreach ($scopes as $scope) {
            $model->db->table('api_key_scopes')->insert(['api_key_id' => $id, 'scope' => $scope]);
        }

        CLI::newLine();
        CLI::write('API key created (id ' . $id . ').', 'green');
        CLI::write('  Name:   ' . $name);
        CLI::write('  Scopes: ' . implode(', ', $scopes));
        CLI::write('  Key (shown once): ' . CLI::color($prefix . '.' . $secret, 'yellow'));
        CLI::write('  Header: Authorization: Bearer ' . $prefix . '.' . $secret);
        CLI::newLine();
    }
}

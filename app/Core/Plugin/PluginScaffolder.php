<?php

declare(strict_types=1);

namespace App\Core\Plugin;

use InvalidArgumentException;

/**
 * Generates a self-contained plugin skeleton: manifest, Plugin class (registers
 * a starter resource), an owned migration for that resource's table, and a
 * README. Pure content generation so it is unit-testable; MakePlugin writes it.
 */
final class PluginScaffolder
{
    /**
     * @return array{vendor: string, name: string}
     */
    public static function parse(string $vendorName): array
    {
        if (preg_match('#^([A-Z][A-Za-z0-9]*)/([A-Z][A-Za-z0-9]*)$#', $vendorName, $m) !== 1) {
            throw new InvalidArgumentException('Expected Vendor/Name in StudlyCase, e.g. Acme/Billing.');
        }

        return ['vendor' => $m[1], 'name' => $m[2]];
    }

    /**
     * Relative-path => file-contents for the whole plugin.
     *
     * @return array<string, string>
     */
    public static function files(string $vendorName): array
    {
        ['vendor' => $vendor, 'name' => $name] = self::parse($vendorName);

        $namespace = "Plugins\\{$vendor}\\{$name}";
        $slug      = strtolower($name);
        $table     = $slug;
        $migration = gmdate('Y-m-d-His') . '_Create' . $name;
        $base      = "{$vendor}/{$name}";

        return [
            "{$base}/plugin.json"                                => self::manifest($vendor, $name, $namespace, $slug),
            "{$base}/Plugin.php"                                 => self::pluginClass($namespace, $slug, $table),
            "{$base}/Database/Migrations/{$migration}.php"       => self::migration($namespace, $name, $table),
            "{$base}/README.md"                                  => self::readme($vendor, $name, $slug),
        ];
    }

    private static function manifest(string $vendor, string $name, string $namespace, string $slug): string
    {
        $data = [
            'name'        => "{$vendor}/{$name}",
            'version'     => '1.0.0',
            'namespace'   => $namespace,
            'description' => "{$vendor}/{$name} plugin.",
            'provides'    => ['resources' => [$slug]],
            'requires'    => ['core' => '^1.0'],
            'enabled'     => true,
        ];

        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    private static function pluginClass(string $namespace, string $slug, string $table): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use App\\Core\\Plugin\\PluginInterface;
            use App\\Core\\Plugin\\PluginManager;

            final class Plugin implements PluginInterface
            {
                public function register(PluginManager \$manager): void
                {
                    \$manager->registerResource('{$slug}', [
                        'table'      => '{$table}',
                        'primaryKey' => 'id',
                        'fillable'   => ['name'],
                        'hidden'     => [],
                        'rules'      => [
                            'create' => ['name' => 'required|max_length[200]'],
                            'update' => ['name' => 'permit_empty|max_length[200]'],
                        ],
                        'sortable'    => ['name', 'created_at'],
                        'filterable'  => ['name'],
                        'defaultSort' => '-created_at',
                        'perPage'     => ['default' => 25, 'max' => 100],
                        'timestamps'  => true,
                        // Output casts: MySQLi returns every column as a string, so declare
                        // types here or the API/webhooks emit `id` as "1" and timestamps in
                        // naive `Y-m-d H:i:s`. With these, a new resource matches the framework
                        // contract out of the box — integer id, ISO-8601 `Z` timestamps — so an
                        // n8n workflow parses it with the same rule as every other resource.
                        // Add 'price' => 'float' etc. as you add typed columns.
                        'casts'       => ['id' => 'int', 'created_at' => 'datetime', 'updated_at' => 'datetime'],
                    ]);
                }

                public function boot(): void
                {
                    // Subscribe to resource.beforeSave / beforeQuery / serialize here if needed.
                }
            }

            PHP;
    }

    private static function migration(string $namespace, string $name, string $table): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace}\\Database\\Migrations;

            use CodeIgniter\\Database\\Migration;

            final class Create{$name} extends Migration
            {
                public function up(): void
                {
                    \$this->forge->addField([
                        'id'         => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                        'name'       => ['type' => 'VARCHAR', 'constraint' => 200],
                        'created_at' => ['type' => 'DATETIME', 'null' => true],
                        'updated_at' => ['type' => 'DATETIME', 'null' => true],
                    ]);
                    \$this->forge->addPrimaryKey('id');
                    \$this->forge->createTable('{$table}', true);
                }

                public function down(): void
                {
                    \$this->forge->dropTable('{$table}', true);
                }
            }

            PHP;
    }

    private static function readme(string $vendor, string $name, string $slug): string
    {
        return <<<MD
            # {$vendor}/{$name}

            A MicroService plugin. Contributes the `{$slug}` resource to the generic CRUD engine.

            ## Enable & migrate

            ```bash
            php spark plugin:list          # confirm it's discovered
            php spark migrate --all        # run this plugin's migration
            php spark key:create --scopes {$slug}:read,{$slug}:write
            php spark docs:generate        # refresh OpenAPI / Postman / Markdown
            ```

            The resource is then available at `/api/v1/{$slug}` with full CRUD, filtering,
            auth scopes (`{$slug}:read|write|delete`), audit, archival delete, idempotency,
            and generated docs — all without touching the core (`app/`).

            Customise the resource in `Plugin.php`, the schema in `Database/Migrations/`,
            and hook lifecycle events in `Plugin::boot()`.
            MD;
    }
}

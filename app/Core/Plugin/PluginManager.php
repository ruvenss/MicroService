<?php

declare(strict_types=1);

namespace App\Core\Plugin;

/**
 * Discovers and boots plugins under plugins/<Vendor>/<Name>/, then exposes what
 * they contributed (currently resource definitions). The sealed core consults
 * this so features can be delivered entirely from plugins/ — app/ stays untouched.
 *
 * Discovery: each plugin has a plugin.json manifest and a `Plugin` class
 * (PSR-4 `Plugins\…`) implementing PluginInterface. Disabled plugins are skipped.
 */
final class PluginManager
{
    private static ?self $instance = null;

    /** @var array<string, array<string, mixed>> slug => resource definition */
    private array $resources = [];

    /** @var list<array<string, mixed>> loaded manifests */
    private array $manifests = [];

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->discover();
        }

        return self::$instance;
    }

    /** For tests, so a fresh scan can run. */
    public static function reset(): void
    {
        self::$instance = null;
    }

    private function discover(): void
    {
        $base = ROOTPATH . 'plugins';
        if (! is_dir($base)) {
            return;
        }

        $instances = [];

        foreach (glob($base . '/*/*/plugin.json') ?: [] as $manifestPath) {
            $manifest = json_decode((string) @file_get_contents($manifestPath), true);
            if (! is_array($manifest) || ($manifest['enabled'] ?? true) !== true) {
                continue;
            }

            $namespace = trim((string) ($manifest['namespace'] ?? ''), '\\');
            $class     = $namespace . '\\Plugin';
            if ($namespace === '' || ! class_exists($class)) {
                continue;
            }

            $plugin = new $class();
            if (! $plugin instanceof PluginInterface) {
                continue;
            }

            $this->manifests[] = $manifest;
            $plugin->register($this);
            $instances[] = $plugin;
        }

        foreach ($instances as $plugin) {
            $plugin->boot();
        }
    }

    /**
     * @param array<string, mixed> $definition
     */
    public function registerResource(string $slug, array $definition): void
    {
        $this->resources[$slug] = $definition;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function resources(): array
    {
        return $this->resources;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function manifests(): array
    {
        return $this->manifests;
    }

    /**
     * Scan every plugin manifest (enabled or not) for tooling — `plugin:list`,
     * `plugin:enable`, `plugin:disable`. Unlike discover(), this loads no classes,
     * so it also sees disabled or broken plugins.
     *
     * @return list<array{path: string, name: string, version: string, namespace: string, enabled: bool, resources: list<string>}>
     */
    public static function catalog(): array
    {
        $base = ROOTPATH . 'plugins';
        $out  = [];

        foreach (glob($base . '/*/*/plugin.json') ?: [] as $path) {
            $manifest = json_decode((string) @file_get_contents($path), true);
            if (! is_array($manifest)) {
                continue;
            }
            $resources = $manifest['provides']['resources'] ?? [];
            $out[]     = [
                'path'      => $path,
                'name'      => (string) ($manifest['name'] ?? ''),
                'version'   => (string) ($manifest['version'] ?? '?'),
                'namespace' => (string) ($manifest['namespace'] ?? '?'),
                'enabled'   => ($manifest['enabled'] ?? true) === true,
                'resources' => is_array($resources) ? array_values($resources) : [],
            ];
        }

        return $out;
    }

    /**
     * Flip a plugin's `enabled` flag in its manifest, matched by its `name`
     * (e.g. `Sample/Catalog`, case-insensitively). Returns the updated catalog
     * entry, or null when no such plugin exists. plugin.json is rewritten
     * pretty-printed so it stays human- and diff-friendly. The change takes effect
     * on the next boot (discovery reads the manifest at startup).
     *
     * @return array{path: string, name: string, version: string, namespace: string, enabled: bool, resources: list<string>}|null
     */
    public static function setEnabled(string $name, bool $enabled): ?array
    {
        foreach (self::catalog() as $entry) {
            if (strcasecmp($entry['name'], $name) !== 0) {
                continue;
            }

            $manifest            = json_decode((string) file_get_contents($entry['path']), true);
            $manifest['enabled'] = $enabled;
            file_put_contents(
                $entry['path'],
                json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            );

            $entry['enabled'] = $enabled;

            return $entry;
        }

        return null;
    }
}

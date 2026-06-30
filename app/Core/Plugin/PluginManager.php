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
}

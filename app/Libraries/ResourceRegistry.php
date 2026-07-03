<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Core\Plugin\PluginManager;
use Config\Resources;

/**
 * Resolves resource slugs to ResourceDefinition objects, aggregating the core
 * config (Config\Resources) with everything plugins contribute (PluginManager).
 * Plugin resources can override core entries of the same slug.
 */
final class ResourceRegistry
{
    /** @var array<string, ResourceDefinition> */
    private array $cache = [];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $merged = null;

    public function __construct(private readonly Resources $config)
    {
    }

    public static function instance(): self
    {
        return new self(config('Resources'));
    }

    public function has(string $slug): bool
    {
        return isset($this->all()[$slug]);
    }

    public function get(string $slug): ?ResourceDefinition
    {
        $all = $this->all();
        if (! isset($all[$slug])) {
            return null;
        }

        return $this->cache[$slug] ??= ResourceDefinition::fromArray($slug, $all[$slug]);
    }

    /**
     * @return list<string>
     */
    public function slugs(): array
    {
        return array_keys($this->all());
    }

    /**
     * Core config resources merged with plugin-contributed ones.
     *
     * @return array<string, array<string, mixed>>
     */
    private function all(): array
    {
        return $this->merged ??= array_merge($this->config->resources, PluginManager::instance()->resources());
    }
}

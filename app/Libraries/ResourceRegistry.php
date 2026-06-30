<?php

declare(strict_types=1);

namespace App\Libraries;

use Config\Resources;

/**
 * Resolves resource slugs to ResourceDefinition objects from the registry,
 * caching the built definitions per request.
 */
final class ResourceRegistry
{
    /** @var array<string, ResourceDefinition> */
    private array $cache = [];

    public function __construct(private readonly Resources $config)
    {
    }

    public static function instance(): self
    {
        return new self(config('Resources'));
    }

    public function has(string $slug): bool
    {
        return isset($this->config->resources[$slug]);
    }

    public function get(string $slug): ?ResourceDefinition
    {
        if (! $this->has($slug)) {
            return null;
        }

        return $this->cache[$slug] ??= ResourceDefinition::fromArray($slug, $this->config->resources[$slug]);
    }

    /**
     * @return list<string>
     */
    public function slugs(): array
    {
        return array_keys($this->config->resources);
    }
}

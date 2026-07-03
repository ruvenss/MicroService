<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Core resource registry — aggregated with plugin-contributed resources by
 * ResourceRegistry. The sealed core ships NO business resources: every resource
 * is delivered from a plugin under plugins/ (see docs/ARCHITECTURE.md §15). The
 * sample `products` resource lives in plugins/Sample/Catalog.
 *
 * Leave this empty unless adding a genuinely framework-level resource.
 */
class Resources extends BaseConfig
{
    /**
     * @var array<string, array<string, mixed>>
     */
    public array $resources = [];
}

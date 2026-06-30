<?php

declare(strict_types=1);

namespace App\Core\Plugin;

/**
 * Contract every plugin's `Plugin` class implements. Plugins extend the sealed
 * core through this interface — they register into the framework, never edit it.
 */
interface PluginInterface
{
    /**
     * Contribute to the framework: register resource definitions, (later) routes,
     * events, and services via the manager. Runs during discovery.
     */
    public function register(PluginManager $manager): void;

    /**
     * Cross-plugin wiring that needs all plugins registered first. Runs after
     * every plugin's register().
     */
    public function boot(): void;
}

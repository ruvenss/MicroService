<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

// Unknown paths and the bare root return a neutral problem+json (no engine
// disclosure). This replaces CodeIgniter's default welcome page.
$routes->set404Override('App\Controllers\Api\Errors::notFound');

// Versioned API surface. n8n and other clients target /api/v1/*.
$routes->group('api/v1', static function (RouteCollection $routes): void {
    $routes->get('health', 'Api\Health::index');
});

// Diagnostic-only: a route that throws, so the global exception handler can be
// exercised (it returns neutral problem+json). Never registered in production.
if (ENVIRONMENT !== 'production') {
    $routes->get('api/v1/_throw', static function (): void {
        throw new RuntimeException('boom should not leak');
    });
}

<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

// Unknown paths and the bare root return a neutral problem+json (no engine
// disclosure). This replaces CodeIgniter's default welcome page.
$routes->set404Override('App\Controllers\Api\Errors::notFound');

// Versioned API surface. n8n and other clients target /api/v1/*.
$routes->group('api/v1', static function (RouteCollection $routes): void {
    $routes->get('health', 'Api\Health::index');
    $routes->get('_resources', 'Api\Discovery::resources');
});

// Diagnostic-only: a route that throws, so the global exception handler can be
// exercised (it returns neutral problem+json). Never registered in production.
if (ENVIRONMENT !== 'production') {
    $routes->get('api/v1/_throw', static function (): void {
        throw new RuntimeException('boom should not leak');
    });
}

// Generic CRUD engine. Declared AFTER the explicit routes above so reserved
// paths (health, _throw) win. The resource slug is resolved against the
// registry (Config\Resources); unknown slugs return a neutral 404.
$routes->group('api/v1', static function (RouteCollection $routes): void {
    $routes->get('(:segment)', 'Api\ResourceController::index/$1');
    $routes->post('(:segment)', 'Api\ResourceController::create/$1');
    $routes->get('(:segment)/(:segment)', 'Api\ResourceController::show/$1/$2');
    $routes->put('(:segment)/(:segment)', 'Api\ResourceController::update/$1/$2');
    $routes->patch('(:segment)/(:segment)', 'Api\ResourceController::update/$1/$2');
    $routes->delete('(:segment)/(:segment)', 'Api\ResourceController::delete/$1/$2');
});

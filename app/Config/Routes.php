<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

// Unknown paths and the bare root return a neutral problem+json (no engine
// disclosure). This replaces CodeIgniter's default welcome page.
$routes->set404Override('App\Controllers\Api\Errors::notFound');

// ── Open endpoints (no auth) ─────────────────────────────────────────────
// Health is unauthenticated so monitors / n8n schedule checks can reach it.
$routes->get('api/v1/health', 'Api\Health::index');

// Diagnostic-only: a route that throws, exercising the global exception handler.
// Never registered in production.
if (ENVIRONMENT !== 'production') {
    $routes->get('api/v1/_throw', static function (): void {
        throw new RuntimeException('boom should not leak');
    });
}

// ── Authenticated endpoints ──────────────────────────────────────────────
// Discovery requires a valid key (any scope).
$routes->get('api/v1/_resources', 'Api\Discovery::resources', ['filter' => 'apikey']);

// Generic CRUD engine. Filters run in order: authenticate → authorize scope →
// validate JSON body. Declared AFTER the reserved paths above so they win.
// The resource slug is resolved against the registry; unknown slugs → neutral 404.
$routes->group('api/v1', ['filter' => ['apikey', 'permission', 'contentguard']], static function (RouteCollection $routes): void {
    $routes->get('(:segment)', 'Api\ResourceController::index/$1');
    $routes->post('(:segment)', 'Api\ResourceController::create/$1');
    $routes->get('(:segment)/(:segment)', 'Api\ResourceController::show/$1/$2');
    $routes->put('(:segment)/(:segment)', 'Api\ResourceController::update/$1/$2');
    $routes->patch('(:segment)/(:segment)', 'Api\ResourceController::update/$1/$2');
    $routes->delete('(:segment)/(:segment)', 'Api\ResourceController::delete/$1/$2');
});

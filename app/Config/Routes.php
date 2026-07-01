<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

// Unknown paths and the bare root return a neutral problem+json (no engine
// disclosure). This replaces CodeIgniter's default welcome page.
$routes->set404Override('App\Controllers\Api\Errors::notFound');

// ── Open endpoints (no auth) ─────────────────────────────────────────────
// Health is unauthenticated so monitors / n8n schedule checks can reach it.
// GET routes also answer HEAD (same status/headers, empty body — cheap probes)
// via `match(['get','head'], …)`; the server drops the body for HEAD.
$routes->match(['get', 'head'], 'api/v1/health', 'Api\Health::index');

// Diagnostic-only: a route that throws, exercising the global exception handler.
// Never registered in production.
if (ENVIRONMENT !== 'production') {
    $routes->get('api/v1/_throw', static function (): void {
        throw new RuntimeException('boom should not leak');
    });
}

// ── Authenticated endpoints ──────────────────────────────────────────────
// Authenticated meta endpoints (valid key; per-endpoint scope checks live in the
// controllers). Usage is tracked. Declared before the generic CRUD group so the
// underscore-prefixed paths win over the resource matcher.
$routes->group('api/v1', ['filter' => ['apikey', 'usagetracker']], static function (RouteCollection $routes): void {
    $routes->match(['get', 'head'], '_me', 'Api\Me::index');
    $routes->match(['get', 'head'], '_resources', 'Api\Discovery::resources');
    $routes->match(['get', 'head'], '_archive', 'Api\Archive::index');
    $routes->match(['get', 'head'], '_archive/(:segment)', 'Api\Archive::show/$1');
    $routes->post('_archive/(:segment)/restore', 'Api\Archive::restore/$1');
    $routes->match(['get', 'head'], '_audit', 'Api\Audit::index');
});

// Generic CRUD engine. Filters run in order: authenticate → rate-limit →
// authorize scope → validate JSON body, then usage tracking on the way out.
// Declared AFTER the reserved paths above so they win. The resource slug is
// resolved against the registry; unknown slugs → neutral 404.
$routes->group('api/v1', ['filter' => ['apikey', 'ratelimit', 'permission', 'idempotency', 'contentguard', 'usagetracker']], static function (RouteCollection $routes): void {
    $routes->match(['get', 'head'], '(:segment)', 'Api\ResourceController::index/$1');
    $routes->post('(:segment)', 'Api\ResourceController::create/$1');
    $routes->patch('(:segment)', 'Api\ResourceController::updateCollection/$1');
    $routes->delete('(:segment)', 'Api\ResourceController::deleteCollection/$1');
    $routes->match(['get', 'head'], '(:segment)/(:segment)', 'Api\ResourceController::show/$1/$2');
    $routes->put('(:segment)/(:segment)', 'Api\ResourceController::update/$1/$2');
    $routes->patch('(:segment)/(:segment)', 'Api\ResourceController::update/$1/$2');
    $routes->delete('(:segment)/(:segment)', 'Api\ResourceController::delete/$1/$2');
});

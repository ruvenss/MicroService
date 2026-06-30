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

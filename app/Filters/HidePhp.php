<?php

declare(strict_types=1);

namespace App\Filters;

use App\Libraries\ApiProblem;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Hides PHP at the application layer, as a backstop to the Apache rewrite.
 *
 * Any request whose ORIGINAL URI path references a .php file — including
 * `/index.php/<route>` PATH_INFO, which Apache's server-context rewrite does not
 * reliably intercept — returns a neutral 404. Because it inspects the raw
 * REQUEST_URI (unchanged by mod_rewrite's internal rewrite of clean URLs), real
 * requests like `/api/v1/health` pass through untouched.
 */
class HidePhp implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $uri = (string) ($request->getServer('REQUEST_URI') ?? ($_SERVER['REQUEST_URI'] ?? ''));

        if (self::referencesPhp($uri)) {
            return ApiProblem::respond(404);
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }

    /**
     * True if the path component (query string ignored) targets a .php file.
     */
    public static function referencesPhp(string $requestUri): bool
    {
        $path = strtok($requestUri, '?');

        return $path !== false && preg_match('#\.php(/|$)#i', $path) === 1;
    }
}

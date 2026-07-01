<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Stealth / anti-fingerprinting filter.
 *
 * Goal: if the microservice is ever exposed, a probe should not be able to tell
 * what runs behind it (PHP, CodeIgniter, Apache version, etc.). Runs on every
 * response. Full server-token masking also happens at the Apache vhost layer
 * (see docs/ARCHITECTURE.md §18); this is the application-level half so the
 * protection holds even on the PHP dev server.
 */
class Stealth implements FilterInterface
{
    /**
     * Generic, engine-neutral value advertised in the Server header.
     * Reveals nothing about PHP / CodeIgniter / Apache.
     */
    private const SERVER_TOKEN = 'MicroService';

    public function before(RequestInterface $request, $arguments = null)
    {
        // no-op: stealth is applied on the way out
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        self::harden($response);

        return $response;
    }

    /**
     * Apply anti-fingerprinting + security headers to a response.
     *
     * Exposed statically so the global exception handler can harden error
     * responses too — those are produced outside the normal filter chain.
     */
    public static function harden(ResponseInterface $response): void
    {
        // ── Remove engine fingerprints ───────────────────────────────────
        // X-Powered-By is injected by the PHP SAPI itself (expose_php), not the
        // framework header bag — drop it at the PHP level too. The production
        // container additionally sets expose_php=Off.
        if (function_exists('header_remove')) {
            header_remove('X-Powered-By');
        }
        $response->removeHeader('X-Powered-By');     // PHP
        $response->removeHeader('X-CodeIgniter-Version');

        // CodeIgniter DebugToolbar correlation headers (development only) would
        // out the framework instantly — strip them unconditionally.
        $response->removeHeader('Debugbar-Time');
        $response->removeHeader('Debugbar-Link');

        $response->setHeader('Server', self::SERVER_TOKEN);

        // ── Baseline security headers (API defaults) ─────────────────────
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $response->setHeader('X-Frame-Options', 'DENY');
        $response->setHeader('Referrer-Policy', 'no-referrer');
        $response->setHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        $response->setHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

        // A machine-to-machine API is never something a search engine should index —
        // being indexed would make an exposed instance discoverable via search (a recon
        // vector). Belt-and-suspenders with public/robots.txt (which a crawler only
        // reads if it already found the host); this rides on every fetched response.
        $response->setHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}

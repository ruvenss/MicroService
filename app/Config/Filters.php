<?php

namespace Config;

use App\Filters\ApiKeyAuth;
use App\Filters\ContentGuard;
use App\Filters\HidePhp;
use App\Filters\RateLimit;
use App\Filters\RequestId;
use App\Filters\RequirePermission;
use App\Filters\Stealth;
use App\Filters\UsageTracker;
use CodeIgniter\Config\Filters as BaseFilters;
use CodeIgniter\Filters\Cors;
use CodeIgniter\Filters\CSRF;
use CodeIgniter\Filters\DebugToolbar;
use CodeIgniter\Filters\ForceHTTPS;
use CodeIgniter\Filters\Honeypot;
use CodeIgniter\Filters\InvalidChars;
use CodeIgniter\Filters\PageCache;
use CodeIgniter\Filters\PerformanceMetrics;
use CodeIgniter\Filters\SecureHeaders;

class Filters extends BaseFilters
{
    /**
     * Configures aliases for Filter classes to
     * make reading things nicer and simpler.
     *
     * @var array<string, class-string|list<class-string>>
     *
     * [filter_name => classname]
     * or [filter_name => [classname1, classname2, ...]]
     */
    public array $aliases = [
        'csrf'          => CSRF::class,
        'toolbar'       => DebugToolbar::class,
        'honeypot'      => Honeypot::class,
        'invalidchars'  => InvalidChars::class,
        'secureheaders' => SecureHeaders::class,
        'cors'          => Cors::class,
        'forcehttps'    => ForceHTTPS::class,
        'pagecache'     => PageCache::class,
        'performance'   => PerformanceMetrics::class,
        'stealth'       => Stealth::class,
        'requestid'     => RequestId::class,
        'contentguard'  => ContentGuard::class,
        'apikey'        => ApiKeyAuth::class,
        'permission'    => RequirePermission::class,
        'ratelimit'     => RateLimit::class,
        'usagetracker'  => UsageTracker::class,
        'hidephp'       => HidePhp::class,
    ];

    /**
     * List of special required filters.
     *
     * The filters listed here are special. They are applied before and after
     * other kinds of filters, and always applied even if a route does not exist.
     *
     * Filters set by default provide framework functionality. If removed,
     * those functions will no longer work.
     *
     * @see https://codeigniter.com/user_guide/incoming/filters.html#provided-filters
     *
     * @var array{before: list<string>, after: list<string>}
     */
    public array $required = [
        'before' => [
            'hidephp',    // 404 any .php request (PATH_INFO-proof) before anything else
            'requestid',  // Establish correlation id as early as possible
            'forcehttps', // Force Global Secure Requests
            'pagecache',  // Web Page Caching
        ],
        'after' => [
            'pagecache',   // Web Page Caching
            'performance', // Performance Metrics
            'requestid',   // Echo X-Request-Id
            // 'toolbar' is intentionally removed: the CodeIgniter DebugToolbar
            // injects Debugbar-* headers via a post-filter event (which a filter
            // cannot strip) and is useless for a headless JSON API. Leaving it
            // out keeps the engine hidden even in development.
            'stealth',     // Strip engine fingerprints + add security headers (always last)
        ],
    ];

    /**
     * List of filter aliases that are always
     * applied before and after every request.
     *
     * @var array{
     *     before: array<string, array{except: list<string>|string}>|list<string>,
     *     after: array<string, array{except: list<string>|string}>|list<string>
     * }
     */
    public array $globals = [
        'before' => [
            // 'honeypot',
            // 'csrf',
            // 'invalidchars',
        ],
        'after' => [
            // 'honeypot',
            // 'secureheaders',
        ],
    ];

    /**
     * List of filter aliases that works on a
     * particular HTTP method (GET, POST, etc.).
     *
     * Example:
     * 'POST' => ['foo', 'bar']
     *
     * If you use this, you should disable auto-routing because auto-routing
     * permits any HTTP method to access a controller. Accessing the controller
     * with a method you don't expect could bypass the filter.
     *
     * @var array<string, list<string>>
     */
    public array $methods = [];

    /**
     * List of filter aliases that should run on any
     * before or after URI patterns.
     *
     * Example:
     * 'isLoggedIn' => ['before' => ['account/*', 'profiles/*']]
     *
     * @var array<string, array<string, list<string>>>
     */
    // Auth, permission, and content-guard are applied per-route (see Routes.php)
    // so ordering is explicit (auth → permission → content) and open endpoints
    // (health, _throw) stay unauthenticated.
    public array $filters = [];
}

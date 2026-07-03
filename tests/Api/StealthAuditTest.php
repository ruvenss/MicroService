<?php

declare(strict_types=1);

use App\Libraries\ApiExceptionHandler;
use Tests\Support\FeatureTestCase;

/**
 * "In case exposed" regression guard: NO response — across every status code and
 * the uncaught-exception path — may leak the engine (PHP / CodeIgniter / Apache /
 * stack traces / file paths), and all carry the neutral Server token and security
 * headers. Complements StealthHeadersTest (which only covered the 404 path).
 *
 * @internal
 */
final class StealthAuditTest extends FeatureTestCase
{
    /** Substrings that would out the engine or internals if they ever appeared. */
    private const FINGERPRINTS = [
        'CodeIgniter', 'codeigniter', 'Xdebug', 'xdebug', 'Whoops',
        'Stack trace', '#0 ', '/var/www', '/system/', 'vendor/', 'app\\Controllers',
        'Fatal error', 'Call to ', '.php on line', 'PHP Warning',
    ];

    /**
     * Assert a raw HTTP response body + headers reveal nothing about the engine.
     */
    private function assertStealthy(\CodeIgniter\HTTP\ResponseInterface $response, string $context): void
    {
        // Engine fingerprints scrubbed from headers.
        $this->assertSame('MicroService', $response->getHeaderLine('Server'), "{$context}: Server header");
        $this->assertSame('', $response->getHeaderLine('X-Powered-By'), "{$context}: X-Powered-By");
        $this->assertSame('', $response->getHeaderLine('X-CodeIgniter-Version'), "{$context}: CI version header");
        $this->assertSame('', $response->getHeaderLine('Debugbar-Time'), "{$context}: Debugbar header");

        // Baseline security headers present on every response.
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'), "{$context}: nosniff");

        // The API must never be search-indexed (discovery vector if exposed).
        $this->assertStringContainsString('noindex', $response->getHeaderLine('X-Robots-Tag'), "{$context}: X-Robots-Tag noindex");

        // Per-key/sensitive responses must never be storable by a shared cache/CDN
        // (defence if the service is exposed behind one); manual ETag/If-None-Match
        // revalidation is unaffected.
        $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'), "{$context}: Cache-Control no-store");

        // Body carries no engine/internals leak.
        $body = (string) $response->getBody();
        foreach (self::FINGERPRINTS as $needle) {
            $this->assertStringNotContainsString($needle, $body, "{$context}: body leaked '{$needle}'");
        }
    }

    public function testStealthAcrossEveryResponsePath(): void
    {
        $auth = $this->authHeaders(['products:read']);

        // 200 — open health endpoint.
        $this->assertStealthy($this->get('api/v1/health')->response(), '200 health');

        // 401 — no API key.
        $this->assertStealthy($this->get('api/v1/products')->response(), '401 no-key');

        // 404 — unknown path, and an unknown (unregistered) resource.
        $this->assertStealthy($this->get('totally/unknown/path')->response(), '404 unknown-path');
        $this->assertStealthy($this->withHeaders($auth)->get('api/v1/nonesuch')->response(), '404 unknown-resource');

        // 400 — malformed JSON body on a write.
        $this->assertStealthy(
            $this->withHeaders($this->authHeaders(['products:write']) + ['Content-Type' => 'application/json'])
                ->withBody('{bad json')->post('api/v1/products')->response(),
            '400 bad-json',
        );

        // 415 — wrong content type.
        $this->assertStealthy(
            $this->withHeaders($this->authHeaders(['products:write']) + ['Content-Type' => 'text/plain'])
                ->withBody('x')->post('api/v1/products')->response(),
            '415 wrong-type',
        );
    }

    public function testStealthOn413Oversized(): void
    {
        $huge = '{"name":"' . str_repeat('a', 1_100_000) . '"}';
        $result = $this->withHeaders($this->authHeaders(['products:write']) + ['Content-Type' => 'application/json'])
            ->withBody($huge)->post('api/v1/products');

        $result->assertStatus(413);
        $this->assertStealthy($result->response(), '413 oversized');
    }

    public function testStealthOn429RateLimited(): void
    {
        $headers = ['Authorization' => 'Bearer ' . $this->makeKey(['products:read'], 'active', null, 2)];
        $this->withHeaders($headers)->get('api/v1/products'); // 1
        $this->withHeaders($headers)->get('api/v1/products'); // 2 (allowance spent)
        $result = $this->withHeaders($headers)->get('api/v1/products'); // 429

        $result->assertStatus(429);
        $this->assertStealthy($result->response(), '429 rate-limited');
    }

    public function testNoStockCodeIgniterFaviconIsShipped(): void
    {
        // Favicon-hash fingerprinting (e.g. Shodan) identifies a framework from the
        // bytes of /favicon.ico. Shipping CodeIgniter's stock favicon would out the
        // engine to any scanner regardless of the masked Server header, so a headless
        // API must not serve it — absent (neutral 404) is ideal; a custom one is fine,
        // but never the byte-exact framework default.
        $root    = dirname(__DIR__, 2);
        $shipped = $root . '/public/favicon.ico';

        if (! is_file($shipped)) {
            $this->assertFileDoesNotExist($shipped); // absent — nothing to fingerprint
            return;
        }

        $frameworkDefault = $root . '/vendor/codeigniter4/framework/public/favicon.ico';
        $this->assertNotSame(
            md5_file($frameworkDefault),
            md5_file($shipped),
            'public/favicon.ico is the stock CodeIgniter favicon — a byte-exact engine fingerprint. Remove it or replace with a custom icon.',
        );
    }

    public function testTrailingSlashIsNormalizedNotRedirected(): void
    {
        // The .htaccess deliberately drops the stock CI4 trailing-slash `R=301`
        // redirect (it would emit Apache's own engine-revealing "Moved Permanently"
        // HTML page). That is only safe because the app itself treats `/x/` as `/x`.
        // Lock that router behaviour so a future change can't silently 404 every
        // trailing-slash request that n8n or a human might send.
        $slash = $this->get('api/v1/health/')->response();
        $this->assertSame(200, $slash->getStatusCode(), 'trailing-slash health');
        $this->assertStealthy($slash, '200 health trailing-slash');
        $this->assertSame(
            $this->get('api/v1/health')->response()->getStatusCode(),
            $slash->getStatusCode(),
            '`/x/` and `/x` must resolve identically',
        );

        // And an unauthenticated resource with a trailing slash still yields the
        // normal neutral 401 — never an Apache redirect.
        $this->assertSame(401, $this->get('api/v1/products/')->response()->getStatusCode());
    }

    public function testHtaccessEmitsNoEngineRevealingApacheRedirect(): void
    {
        // Apache generates its own text/html; charset=iso-8859-1 "Moved" page for any
        // mod_rewrite `R=3xx` — a clear engine fingerprint that skips our headers and
        // problem+json. The rewrite rules must never issue one; trailing slashes are
        // normalized internally via the front controller instead.
        $raw = (string) file_get_contents(dirname(__DIR__, 2) . '/public/.htaccess');
        // Scan directives only — comment lines legitimately mention `[R=301]` to
        // document why it was removed.
        $htaccess = implode("\n", array_filter(
            explode("\n", $raw),
            static fn (string $line): bool => ! str_starts_with(ltrim($line), '#'),
        ));

        $this->assertDoesNotMatchRegularExpression(
            '/\[[^\]]*R=30[12][^\]]*\]/',
            $htaccess,
            '.htaccess issues an external redirect (R=30x) — Apache would serve its own HTML redirect page and out the engine.',
        );
        // The trailing-slash rule must hand off to the front controller (internal),
        // not redirect.
        $this->assertMatchesRegularExpression(
            '#RewriteRule\s+\S*/\S*\$\s+index\.php#',
            $htaccess,
            'trailing-slash normalization must rewrite to index.php internally.',
        );
    }

    public function testUncaughtExceptionHandlerIsNeutral(): void
    {
        // Directly exercise the last-line-of-defence handler: even a message full
        // of engine words must not add class/file/line/trace to the body, and the
        // response is hardened problem+json.
        $handler  = new ApiExceptionHandler(config('Exceptions'));
        $response = $handler->prepare(
            new RuntimeException('kaboom'),
            service('response'),
            500,
        );

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('application/problem+json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('MicroService', $response->getHeaderLine('Server'));

        $json = json_decode((string) $response->getBody(), true);
        $this->assertSame(500, $json['status']);
        // Neutral problem shape only — no framework internals.
        foreach (['trace', 'file', 'line', 'class', 'exception'] as $leak) {
            $this->assertArrayNotHasKey($leak, $json, "handler body leaked '{$leak}'");
        }
    }
}

<?php

declare(strict_types=1);

use App\Libraries\Docs\EndpointCatalog;
use App\Libraries\ResourceRegistry;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Contract guard: the generated OpenAPI/Postman/Markdown must cover exactly the
 * real routes — no undocumented endpoint (which would be missing from the Postman
 * collection a human imports) and no documented phantom (which would 404).
 *
 * DocsInSyncTest proves the committed docs match generation; this proves the
 * generation matches the actual routing table.
 *
 * @internal
 */
final class RouteDocCoverageTest extends CIUnitTestCase
{
    /** REST verbs the API documents (HEAD/OPTIONS are implicit, not documented). */
    private const VERBS = ['get', 'post', 'put', 'patch', 'delete'];

    /**
     * Collapse a route pattern or a doc path to a canonical "METHOD a/b/*" form so
     * a generic route (`api/v1/([^/]+)`) and a per-resource doc path
     * (`/api/v1/products`) compare equal. Variable segments — route placeholders,
     * `{id}`/`{resource}`, and any registered resource slug — become `*`; literal
     * meta segments (`_me`, `_archive`, `restore`, …) stay.
     */
    private function canonical(string $method, string $path): string
    {
        $slugs = ResourceRegistry::instance()->slugs();
        $out   = [];

        foreach (explode('/', trim($path, '/')) as $segment) {
            $isVar = $segment === '([^/]+)'
                || $segment === '(:segment)'
                || $segment === '(:any)'
                || (str_starts_with($segment, '{') && str_ends_with($segment, '}'))
                || in_array($segment, $slugs, true);
            $out[] = $isVar ? '*' : $segment;
        }

        return strtoupper($method) . ' ' . implode('/', $out);
    }

    /**
     * The declared route surface, read from Config/Routes.php (the source of truth).
     * CI4's RouteCollection only populates during real request routing, so parsing
     * the declarations is the reliable way to enumerate them in a unit test. Every
     * grouped route lives under the `api/v1` prefix; the few absolute ones already
     * carry it.
     *
     * @return array<string, true> canonical form => true
     */
    private function declaredRoutes(): array
    {
        $source = (string) file_get_contents(APPPATH . 'Config/Routes.php');
        $set    = [];

        $add = function (string $verb, string $pattern) use (&$set): void {
            $verb = strtolower($verb);
            if (! in_array($verb, self::VERBS, true) || str_contains($pattern, '_throw') || str_starts_with($pattern, 'ms_debug')) {
                // HEAD/OPTIONS are implicit; _throw and ms_debug are dev-only surfaces
                // (registered only when ENVIRONMENT=development) and are deliberately NOT
                // part of the documented API contract — they never exist in production.
                return;
            }
            $path                              = str_starts_with($pattern, 'api/v1') ? $pattern : 'api/v1/' . ltrim($pattern, '/');
            $set[$this->canonical($verb, $path)] = true;
        };

        // Single-verb declarations: $routes->get('pattern', …)
        preg_match_all('/\$routes->(' . implode('|', self::VERBS) . ")\(\s*'([^']+)'/i", $source, $single, PREG_SET_ORDER);
        foreach ($single as [, $verb, $pattern]) {
            $add($verb, $pattern);
        }

        // Multi-verb declarations: $routes->match(['get','head'], 'pattern', …)
        preg_match_all("/\\\$routes->match\(\s*\[([^\]]+)\]\s*,\s*'([^']+)'/i", $source, $multi, PREG_SET_ORDER);
        foreach ($multi as [, $verbList, $pattern]) {
            foreach (explode(',', $verbList) as $verb) {
                $add(trim($verb, " \t'\""), $pattern);
            }
        }

        return $set;
    }

    /** @return array<string, true> canonical form => true */
    private function documentedEndpoints(): array
    {
        $set = [];
        foreach (EndpointCatalog::all() as $ep) {
            $set[$this->canonical($ep['method'], $ep['path'])] = true;
        }

        return $set;
    }

    public function testEveryRouteIsDocumented(): void
    {
        $undocumented = array_keys(array_diff_key($this->declaredRoutes(), $this->documentedEndpoints()));

        $this->assertSame(
            [],
            $undocumented,
            'These routes have no OpenAPI/Postman documentation (add them to EndpointCatalog): '
            . implode('; ', $undocumented),
        );
    }

    public function testNoDocumentedEndpointIsAPhantom(): void
    {
        $phantom = array_keys(array_diff_key($this->documentedEndpoints(), $this->declaredRoutes()));

        $this->assertSame(
            [],
            $phantom,
            'These documented endpoints have no matching route (would 404): ' . implode('; ', $phantom),
        );
    }
}

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
        preg_match_all(
            '/\$routes->(' . implode('|', self::VERBS) . ")\(\s*'([^']+)'/i",
            $source,
            $matches,
            PREG_SET_ORDER,
        );

        $set = [];
        foreach ($matches as [, $verb, $pattern]) {
            if (str_contains($pattern, '_throw')) {
                continue; // dev-only diagnostic, intentionally undocumented
            }
            $path = str_starts_with($pattern, 'api/v1') ? $pattern : 'api/v1/' . ltrim($pattern, '/');
            $set[$this->canonical($verb, $path)] = true;
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

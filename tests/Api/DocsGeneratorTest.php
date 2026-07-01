<?php

declare(strict_types=1);

use App\Libraries\Docs\EndpointCatalog;
use App\Libraries\Docs\MarkdownGenerator;
use App\Libraries\Docs\OpenApiGenerator;
use App\Libraries\Docs\PostmanGenerator;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class DocsGeneratorTest extends CIUnitTestCase
{
    public function testCatalogCoversTheProductsResource(): void
    {
        $paths = array_map(static fn (array $e): string => $e['method'] . ' ' . $e['path'], EndpointCatalog::all());

        $this->assertContains('GET /api/v1/products', $paths);
        $this->assertContains('POST /api/v1/products', $paths);
        $this->assertContains('DELETE /api/v1/products/{id}', $paths);
        $this->assertContains('GET /api/v1/health', $paths);
        $this->assertContains('GET /api/v1/_archive', $paths);
    }

    public function testOpenApiIsWellFormed(): void
    {
        $spec = OpenApiGenerator::generate();

        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertArrayHasKey('bearerAuth', $spec['components']['securitySchemes']);
        $this->assertArrayHasKey('get', $spec['paths']['/api/v1/products']);
        $this->assertArrayHasKey('post', $spec['paths']['/api/v1/products']);

        // health is open (no security), products requires auth
        $this->assertSame([], $spec['paths']['/api/v1/health']['get']['security']);
        $this->assertNotSame([], $spec['paths']['/api/v1/products']['get']['security']);

        // create declares a JSON request body with the required sku
        $schema = $spec['paths']['/api/v1/products']['post']['requestBody']['content']['application/json']['schema'];
        $this->assertContains('sku', $schema['required']);
    }

    public function testDocumentsIdempotency409AndHealth503(): void
    {
        $spec = OpenApiGenerator::generate();

        // A write can conflict on an in-progress Idempotency-Key → 409 (with Retry-After).
        $post = $spec['paths']['/api/v1/products']['post']['responses'];
        $this->assertArrayHasKey('409', $post);
        $this->assertArrayHasKey('Retry-After', $post['409']['headers']);

        // Readiness can be degraded → 503 (with Retry-After) on the health endpoint.
        $health = $spec['paths']['/api/v1/health']['get']['responses'];
        $this->assertArrayHasKey('503', $health);
        $this->assertArrayHasKey('Retry-After', $health['503']['headers']);
    }

    public function testPostmanCollectionIsImportable(): void
    {
        $collection = PostmanGenerator::generate();

        $this->assertStringContainsString('v2.1.0', $collection['info']['schema']);
        $this->assertSame('bearer', $collection['auth']['type']);

        $folderNames = array_map(static fn (array $f): string => $f['name'], $collection['item']);
        $this->assertContains('Products', $folderNames);

        $varKeys = array_map(static fn (array $v): string => $v['key'], $collection['variable']);
        $this->assertContains('apiKey', $varKeys);
        $this->assertContains('baseUrl', $varKeys);
    }

    public function testCreateExampleBodyIsValidNotJustTyped(): void
    {
        // The example body a human sends on "Create a products" must satisfy the
        // resource's own validation — in particular status is in_list[active,archived],
        // so the placeholder must be a real enum value (active), not "string" (422).
        $collection = PostmanGenerator::generate();

        $body = null;
        foreach ($collection['item'] as $folder) {
            foreach ($folder['item'] as $req) {
                if (str_starts_with($req['name'], 'Create a products')) {
                    $body = json_decode($req['request']['body']['raw'], true);
                }
            }
        }

        $this->assertNotNull($body);
        $this->assertSame('active', $body['status']);            // valid enum, not "string"
        $this->assertIsFloat($body['price']);                    // typed number, not "0.00"

        // OpenAPI documents the enum constraint too.
        $props = OpenApiGenerator::generate()['paths']['/api/v1/products']['post']['requestBody']['content']['application/json']['schema']['properties'];
        $this->assertSame(['active', 'archived'], $props['status']['enum']);
    }

    public function testMarkdownReferenceContainsResources(): void
    {
        $md = MarkdownGenerator::generate();

        $this->assertStringContainsString('## Products', $md);
        $this->assertStringContainsString('GET /api/v1/products', $md);
        $this->assertStringContainsString('scope', strtolower($md));
    }
}

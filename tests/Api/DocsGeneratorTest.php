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

    public function testMarkdownReferenceContainsResources(): void
    {
        $md = MarkdownGenerator::generate();

        $this->assertStringContainsString('## Products', $md);
        $this->assertStringContainsString('GET /api/v1/products', $md);
        $this->assertStringContainsString('scope', strtolower($md));
    }
}

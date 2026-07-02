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

    public function testResponseSchemaDocumentsResourceFieldsAndTypes(): void
    {
        $spec = OpenApiGenerator::generate();

        // A single-resource GET documents the response `data` fields with real types.
        $data = $spec['paths']['/api/v1/products/{id}']['get']['responses']['200']['content']['application/json']['schema']['properties']['data'];
        $props = $data['properties'];
        $this->assertSame('integer', $props['id']['type']);
        $this->assertSame('number', $props['price']['type']);           // decimal cast → number
        $this->assertSame(['active', 'archived'], $props['status']['enum']);
        $this->assertSame('date-time', $props['created_at']['format']);

        // The list response is an array of that same item shape.
        $list = $spec['paths']['/api/v1/products']['get']['responses']['200']['content']['application/json']['schema']['properties']['data'];
        $this->assertSame('array', $list['type']);
        $this->assertArrayHasKey('price', $list['items']['properties']);
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

        // The unique column (sku) uses Postman's {{$randomUUID}} so re-running the whole
        // collection never trips a duplicate-value 422 on create/update/upsert.
        $this->assertSame('{{$randomUUID}}', $body['sku']);

        // OpenAPI documents the enum constraint too — and keeps a READABLE example for the
        // unique field (no Postman {{...}} syntax leaks into the spec).
        $props = OpenApiGenerator::generate()['paths']['/api/v1/products']['post']['requestBody']['content']['application/json']['schema']['properties'];
        $this->assertSame(['active', 'archived'], $props['status']['enum']);
        $this->assertSame('sku', $props['sku']['example']);
        $this->assertStringNotContainsString('{{', json_encode($props));
    }

    public function testBulkDeleteIsSelfContainedViaAPrerequestThrowaway(): void
    {
        // Bulk delete is destructive, so it can't target the chain's row ({{productsId}})
        // — it would 404 the single-item requests. Instead a pre-request script creates a
        // throwaway row and the body deletes THAT, so the whole collection runs clean.
        $collection = PostmanGenerator::generate();

        $del = null;
        foreach ($collection['item'] as $folder) {
            foreach ($folder['item'] as $req) {
                if ($req['request']['method'] === 'DELETE' && str_starts_with($req['name'], 'Bulk')) {
                    $del = $req;
                }
            }
        }
        $this->assertNotNull($del);
        $this->assertStringContainsString('{{productsDelId}}', $del['request']['body']['raw']);

        $listens = array_column($del['event'] ?? [], 'listen');
        $this->assertContains('prerequest', $listens);
        $prereq = implode("\n", $del['event'][array_search('prerequest', $listens, true)]['script']['exec']);
        $this->assertStringContainsString('pm.sendRequest', $prereq);
        $this->assertStringContainsString("pm.collectionVariables.set('productsDelId'", $prereq);

        // The throwaway-id variable is declared, and OpenAPI keeps the plain example.
        $this->assertContains('productsDelId', array_column($collection['variable'], 'key'));
        $this->assertStringNotContainsString('{{', json_encode(OpenApiGenerator::generate()));
    }

    public function testEveryRequestSelfVerifiesStatusAndEnvelope(): void
    {
        // The collection is meant to be a smoke test a human can run: each request must
        // assert its documented success status + response envelope, so the runner shows a
        // green check per endpoint (not just a status code). Assertions run before the
        // id-capture script, so both must coexist in the one test listener.
        $collection = PostmanGenerator::generate();

        $script = static function (array $req): string {
            foreach ($req['event'] ?? [] as $e) {
                if ($e['listen'] === 'test') {
                    return implode("\n", $e['script']['exec']);
                }
            }

            return '';
        };
        $find = static function (string $prefix) use ($collection, $script): string {
            foreach ($collection['item'] as $folder) {
                foreach ($folder['item'] as $req) {
                    if (str_starts_with($req['name'], $prefix)) {
                        return $script($req);
                    }
                }
            }

            return '';
        };

        // A create asserts 201 + a data object, and still captures the id.
        $create = $find('Create a products');
        $this->assertStringContainsString('pm.expect(pm.response.code).to.be.oneOf([201])', $create);
        $this->assertStringContainsString("to.be.an('object')", $create);
        $this->assertStringContainsString("pm.collectionVariables.set('productsId'", $create); // capture still present

        // A list asserts 200 + a data array.
        $list = $find('List products');
        $this->assertStringContainsString('oneOf([200])', $list);
        $this->assertStringContainsString("to.be.an('array')", $list);

        // Upsert (collection PUT) may create OR update, so it accepts 200 or 201.
        $upsert = $find('Upsert products');
        $this->assertStringContainsString('oneOf([200, 201])', $upsert);

        // Bulk delete returns only a {meta} envelope (no data), so it accepts either key.
        $bulkDelete = $find('Bulk archival delete');
        $this->assertStringContainsString("to.have.any.keys('data', 'meta')", $bulkDelete);

        // A 204 delete asserts the status but has no body to shape-check.
        $delete = $find('Archival delete (moves');
        $this->assertStringContainsString('oneOf([204])', $delete);
        $this->assertStringNotContainsString('pm.response.json()', $delete);
    }

    public function testCreateRequestCapturesIdForTheChain(): void
    {
        // The README promises the create → show/update/delete chain "just runs":
        // creating a record must stash its id into the {slug}Id collection variable
        // so the {{productsId}} path in later requests resolves without hand-copying.
        // Regression: captureId was gated on the request's own {id} path param, which
        // the create endpoint doesn't have, so no script was emitted.
        $collection = PostmanGenerator::generate();

        $create = null;
        foreach ($collection['item'] as $folder) {
            foreach ($folder['item'] as $req) {
                if (str_starts_with($req['name'], 'Create a products')) {
                    $create = $req;
                }
            }
        }

        $this->assertNotNull($create, 'create request missing');
        $this->assertArrayHasKey('event', $create, 'create request has no test script to capture the id');

        $script = implode("\n", $create['event'][0]['script']['exec']);
        $this->assertStringContainsString("pm.collectionVariables.set('productsId'", $script);
        $this->assertStringContainsString('.id', $script);          // captures the primary key
        $this->assertStringContainsString('201', $script);          // only on a successful create

        // The variable the script writes must be declared on the collection.
        $varKeys = array_map(static fn (array $v): string => $v['key'], $collection['variable']);
        $this->assertContains('productsId', $varKeys);
    }

    public function testListRequestsSeedTheIdForChainedRequests(): void
    {
        // The recycle-bin show/restore requests target {{archiveId}}, but nothing
        // creates an archive row through the collection — so without a seed the whole
        // "Audit & recycle bin" chain is un-runnable after import. The archive LIST
        // must stash the first row's id (only if unset, so it never clobbers a live
        // value), and the products LIST likewise seeds productsId.
        $collection = PostmanGenerator::generate();

        $scripts = [];
        foreach ($collection['item'] as $folder) {
            foreach ($folder['item'] as $req) {
                if ($req['request']['method'] === 'GET' && str_starts_with($req['name'], 'List')) {
                    $scripts[$req['name']] = isset($req['event']) ? implode("\n", $req['event'][0]['script']['exec']) : '';
                }
            }
        }

        $archive = $scripts['List archived (deleted) rows.'] ?? null;
        $this->assertNotNull($archive);
        $this->assertStringContainsString("pm.collectionVariables.set('archiveId'", $archive);
        $this->assertStringContainsString("!pm.collectionVariables.get('archiveId')", $archive); // seed only if empty

        $products = $scripts['List products.'] ?? null;
        $this->assertNotNull($products);
        $this->assertStringContainsString("pm.collectionVariables.set('productsId'", $products);
    }

    public function testBulkUpdatePostmanBodyTargetsTheCapturedId(): void
    {
        // The bulk-update example must operate on the just-created row so it runs (200)
        // instead of 422 on a hardcoded id — Postman targets {{productsId}} with a fresh
        // unique value, while OpenAPI/Markdown keep a plain readable id/sku.
        $postman = PostmanGenerator::generate();

        $raw = null;
        foreach ($postman['item'] as $folder) {
            foreach ($folder['item'] as $req) {
                if (str_starts_with($req['name'], 'Bulk update')) {
                    $raw = $req['request']['body']['raw'];
                }
            }
        }
        $this->assertNotNull($raw);
        $this->assertStringContainsString('{{productsId}}', $raw);
        $this->assertStringContainsString('{{$randomUUID}}', $raw);

        // OpenAPI's example for the same endpoint carries no Postman {{...}} syntax.
        $openapi = json_encode(OpenApiGenerator::generate());
        $this->assertStringNotContainsString('{{', (string) $openapi);
    }

    public function testOpenApiServerIsOverridable(): void
    {
        // A human testing a real deployment via Swagger UI shouldn't have to edit the
        // spec: the server is templated with overridable host/scheme variables, defaulted
        // to the local dev server so the committed spec stays deterministic.
        $server = OpenApiGenerator::generate()['servers'][0];

        $this->assertSame('{scheme}://{host}', $server['url']);
        $this->assertSame('localhost:8080', $server['variables']['host']['default']);
        $this->assertSame('http', $server['variables']['scheme']['default']);
        $this->assertContains('https', $server['variables']['scheme']['enum']);
    }

    public function testMarkdownReferenceContainsResources(): void
    {
        $md = MarkdownGenerator::generate();

        $this->assertStringContainsString('## Products', $md);
        $this->assertStringContainsString('GET /api/v1/products', $md);
        $this->assertStringContainsString('scope', strtolower($md));
    }
}

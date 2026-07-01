<?php

declare(strict_types=1);

use Tests\Support\FeatureTestCase;

/**
 * HEAD mirrors GET (same status + headers, no body) so n8n/monitors can run cheap
 * liveness/existence probes. Previously HEAD hit the 404 override.
 *
 * @internal
 */
final class HeadRequestTest extends FeatureTestCase
{
    public function testHeadOnOpenHealthReturns200(): void
    {
        $this->call('head', 'api/v1/health')->assertStatus(200);
    }

    public function testHeadOnResourceRequiresAuthThenSucceeds(): void
    {
        // Anonymous HEAD must not leak existence — it is gated exactly like GET.
        $this->call('head', 'api/v1/products')->assertStatus(401);

        $auth = $this->authHeaders(['products:read']);
        $this->withHeaders($auth)->call('head', 'api/v1/products')->assertStatus(200);
    }

    public function testHeadOnASingleRecordWorks(): void
    {
        $auth = $this->authHeaders(['products:*']);
        $id   = json_decode((string) $this->withHeaders($auth)->withBodyFormat('json')
            ->post('api/v1/products', ['sku' => 'HEAD-1', 'name' => 'N', 'price' => '1.00'])
            ->response()->getBody(), true)['data']['id'];

        $this->withHeaders($auth)->call('head', "api/v1/products/{$id}")->assertStatus(200);
        // Unknown id still 404s under HEAD, same as GET.
        $this->withHeaders($auth)->call('head', 'api/v1/products/999999')->assertStatus(404);
    }

    public function testHeadResponseCarriesTheEtagHeaderLikeGet(): void
    {
        $auth   = $this->authHeaders(['products:read']);
        $result = $this->withHeaders($auth)->call('head', 'api/v1/products');

        $result->assertStatus(200);
        // The conditional-request validator is present on HEAD too (headers mirror GET).
        $this->assertNotSame('', $result->response()->getHeaderLine('ETag'));
    }
}

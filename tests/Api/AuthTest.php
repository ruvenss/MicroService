<?php

declare(strict_types=1);

use Tests\Support\FeatureTestCase;

/**
 * Bearer API-key authentication and scope enforcement on /api/v1/*.
 *
 * @internal
 */
final class AuthTest extends FeatureTestCase
{
    public function testMissingKeyReturns401(): void
    {
        $result = $this->get('api/v1/products');
        $result->assertStatus(401);
        $this->assertSame('Bearer', $result->response()->getHeaderLine('WWW-Authenticate'));
    }

    public function testMalformedTokenReturns401(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer not-a-valid-token'])
            ->get('api/v1/products')->assertStatus(401);
    }

    public function testWrongSecretReturns401(): void
    {
        $prefix = explode('.', $this->makeKey(['products:read']))[0];

        $this->withHeaders(['Authorization' => "Bearer {$prefix}.deadbeef"])
            ->get('api/v1/products')->assertStatus(401);
    }

    public function testValidKeyWithScopeReturns200(): void
    {
        $this->withHeaders($this->authHeaders(['products:read']))
            ->get('api/v1/products')->assertStatus(200);
    }

    public function testValidKeyLackingScopeReturns403(): void
    {
        $result = $this->withHeaders($this->authHeaders(['products:read']))
            ->withBodyFormat('json')
            ->post('api/v1/products', ['sku' => 'X1', 'name' => 'X', 'price' => '1.00']);

        $result->assertStatus(403);
    }

    public function testRevokedKeyReturns401(): void
    {
        $token = $this->makeKey(['products:read'], 'revoked');

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->get('api/v1/products')->assertStatus(401);
    }

    public function testExpiredKeyReturns401(): void
    {
        $token = $this->makeKey(['products:read'], 'active', date('Y-m-d H:i:s', time() - 3600));

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->get('api/v1/products')->assertStatus(401);
    }

    public function testHealthRemainsOpen(): void
    {
        $this->get('api/v1/health')->assertStatus(200);
    }

    public function testScopeMatrixIsEnforcedEndToEndAcrossEveryVerb(): void
    {
        // The satisfies() logic is unit-tested, but this proves the RequirePermission
        // filter is actually wired on EVERY write/delete route — a route missing it would
        // be a hole the unit test can't see. Each single-action key must be 403'd on the
        // verbs it isn't scoped for; the write→delete boundary is the most sensitive.
        $id = json_decode((string) $this->withHeaders($this->authHeaders(['products:*']))->withBodyFormat('json')
            ->post('api/v1/products', ['sku' => 'MTX-1', 'name' => 'N', 'price' => '1.00'])
            ->response()->getBody(), true)['data']['id'];
        $newBody   = ['sku' => 'MTX-2', 'name' => 'N', 'price' => '1.00'];
        $patchBody = ['name' => 'patched'];

        // read: GET only; write + delete verbs are forbidden (non-destructive checks).
        $read = $this->authHeaders(['products:read']);
        $this->withHeaders($read)->get('api/v1/products')->assertStatus(200);
        $this->withHeaders($read)->withBodyFormat('json')->post('api/v1/products', $newBody)->assertStatus(403);
        $this->withHeaders($read)->withBodyFormat('json')->patch("api/v1/products/{$id}", $patchBody)->assertStatus(403);
        $this->withHeaders($read)->withBodyFormat('json')->put('api/v1/products', $newBody)->assertStatus(403); // upsert
        $this->withHeaders($read)->delete("api/v1/products/{$id}")->assertStatus(403);

        // write: create/update, but NOT read and NOT delete (the key boundary).
        $write = $this->authHeaders(['products:write']);
        $this->withHeaders($write)->get('api/v1/products')->assertStatus(403);
        $this->withHeaders($write)->delete("api/v1/products/{$id}")->assertStatus(403);
        $this->withHeaders($write)->withBodyFormat('json')->patch("api/v1/products/{$id}", $patchBody)->assertStatus(200);

        // delete: DELETE only; GET and write verbs forbidden. Destructive step last.
        $del = $this->authHeaders(['products:delete']);
        $this->withHeaders($del)->get('api/v1/products')->assertStatus(403);
        $this->withHeaders($del)->withBodyFormat('json')->post('api/v1/products', $newBody)->assertStatus(403);
        $this->withHeaders($del)->delete("api/v1/products/{$id}")->assertStatus(204);
    }
}

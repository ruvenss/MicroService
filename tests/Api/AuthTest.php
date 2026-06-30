<?php

declare(strict_types=1);

use App\Libraries\AuthContext;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;

/**
 * Bearer API-key authentication and scope enforcement on /api/v1/*.
 *
 * @internal
 */
final class AuthTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use DatabaseTestTrait;
    use AuthTestTrait;

    protected $namespace = 'App';
    protected $refresh   = true;

    protected function setUp(): void
    {
        parent::setUp();
        AuthContext::reset();
    }

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
}

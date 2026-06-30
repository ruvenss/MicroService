<?php

declare(strict_types=1);

use App\Libraries\Authorization;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class AuthorizationTest extends CIUnitTestCase
{
    public function testActionForMethod(): void
    {
        $this->assertSame('read', Authorization::actionForMethod('GET'));
        $this->assertSame('write', Authorization::actionForMethod('POST'));
        $this->assertSame('write', Authorization::actionForMethod('patch'));
        $this->assertSame('delete', Authorization::actionForMethod('DELETE'));
    }

    public function testRequiredScope(): void
    {
        $this->assertSame('products:read', Authorization::requiredScope('products', 'GET'));
        $this->assertSame('products:delete', Authorization::requiredScope('products', 'DELETE'));
    }

    public function testExactScope(): void
    {
        $this->assertTrue(Authorization::satisfies(['products:read'], 'products:read'));
        $this->assertFalse(Authorization::satisfies(['products:read'], 'products:write'));
    }

    public function testResourceWildcard(): void
    {
        $this->assertTrue(Authorization::satisfies(['products:*'], 'products:write'));
        $this->assertFalse(Authorization::satisfies(['products:*'], 'orders:write'));
    }

    public function testActionWildcard(): void
    {
        $this->assertTrue(Authorization::satisfies(['*:read'], 'orders:read'));
        $this->assertFalse(Authorization::satisfies(['*:read'], 'orders:write'));
    }

    public function testGlobalWildcard(): void
    {
        $this->assertTrue(Authorization::satisfies(['*'], 'anything:delete'));
        $this->assertTrue(Authorization::satisfies(['*:*'], 'anything:delete'));
    }

    public function testNoScopesNeverSatisfies(): void
    {
        $this->assertFalse(Authorization::satisfies([], 'products:read'));
    }
}

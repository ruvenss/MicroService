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

    public function testActionsAreFullySeparated(): void
    {
        // Each single-action scope grants ONLY that action — no cross-action escalation.
        // The write→delete boundary is the most sensitive (a write key must not delete).
        foreach (['read', 'write', 'delete'] as $held) {
            foreach (['read', 'write', 'delete'] as $required) {
                $ok = Authorization::satisfies(["products:{$held}"], "products:{$required}");
                $this->assertSame(
                    $held === $required,
                    $ok,
                    "products:{$held} " . ($held === $required ? 'must' : 'must NOT') . " satisfy products:{$required}",
                );
            }
        }
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

    public function testPermitsResourceForAnyAction(): void
    {
        // Any single action, or a wildcard, grants discovery of the resource.
        $this->assertTrue(Authorization::permitsResource(['products:read'], 'products'));
        $this->assertTrue(Authorization::permitsResource(['products:write'], 'products'));
        $this->assertTrue(Authorization::permitsResource(['products:delete'], 'products'));
        $this->assertTrue(Authorization::permitsResource(['products:*'], 'products'));
        $this->assertTrue(Authorization::permitsResource(['*:read'], 'products'));
        $this->assertTrue(Authorization::permitsResource(['*'], 'products'));
    }

    public function testPermitsResourceDeniesUnscopedResource(): void
    {
        $this->assertFalse(Authorization::permitsResource(['orders:read'], 'products'));
        $this->assertFalse(Authorization::permitsResource(['audit:read'], 'products'));
        $this->assertFalse(Authorization::permitsResource([], 'products'));
    }
}

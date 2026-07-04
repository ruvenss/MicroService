<?php

declare(strict_types=1);

use Tests\Support\FeatureTestCase;

/**
 * The debug dashboard is a development-only surface. These tests pin the security
 * contract: outside `development` (the suite runs under `testing`) the routes are
 * not registered at all, so both endpoints return the SAME neutral 404 as any
 * unknown path — the service never reveals that a debug surface exists.
 *
 * @internal
 */
final class MsDebugRouteTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Guard the guard: if the suite ever ran under development these assertions
        // would be vacuous, so make the precondition explicit.
        $this->assertNotSame('development', ENVIRONMENT);
    }

    public function testDashboardPageIsAbsentOutsideDevelopment(): void
    {
        $this->get('ms_debug')->assertStatus(404);
    }

    public function testDataEndpointIsAbsentOutsideDevelopment(): void
    {
        $this->get('ms_debug/data')->assertStatus(404);
    }
}

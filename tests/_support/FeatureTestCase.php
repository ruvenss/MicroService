<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Libraries\AuthContext;
use App\Libraries\IdempotencyContext;
use App\Libraries\RateLimitState;
use App\Libraries\RequestContext;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Base for HTTP feature tests: migrates the App schema into the (transactional)
 * test database and resets per-request state between tests — including the cache
 * that backs rate-limit counters, so buckets never bleed across tests.
 */
abstract class FeatureTestCase extends CIUnitTestCase
{
    use FeatureTestTrait;
    use DatabaseTestTrait;
    use AuthTestTrait;

    protected $namespace = 'App';
    protected $refresh   = true;

    protected function setUp(): void
    {
        parent::setUp();

        cache()->clean();
        AuthContext::reset();
        RateLimitState::reset();
        RequestContext::reset();
        IdempotencyContext::reset();
    }
}

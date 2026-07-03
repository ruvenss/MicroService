<?php

declare(strict_types=1);

use App\Libraries\Cache\AtomicPredisHandler;
use CodeIgniter\Cache\Handlers\PredisHandler;
use Config\Cache;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The cache handler is wired from the environment: with a Redis sidecar present
 * (`REDIS_HOST` set — the docker-compose stack) the app uses the pure-PHP Predis
 * handler so cache/rate-limit/idempotency/counter state is shared across replicas;
 * without it (local dev / tests / single node) it stays on the file handler. The
 * backup is always `file`, so a Redis outage degrades rather than 500s.
 *
 * @internal
 */
final class CacheConfigTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        // Never leak a Redis handler selection into other tests.
        putenv('REDIS_HOST');
        putenv('REDIS_PORT');
        parent::tearDown();
    }

    public function testDefaultsToFileCacheWhenNoRedisHost(): void
    {
        putenv('REDIS_HOST');

        $config = new Cache();

        $this->assertSame('file', $config->handler);
        $this->assertSame('file', $config->backupHandler);
    }

    public function testUsesPredisPointedAtRedisHostWhenSet(): void
    {
        putenv('REDIS_HOST=redis');
        putenv('REDIS_PORT=6380');

        $config = new Cache();

        $this->assertSame('predis', $config->handler);   // pure-PHP client, no phpredis extension
        $this->assertSame('file', $config->backupHandler); // graceful degradation on outage
        $this->assertSame('redis', $config->redis['host']);
        $this->assertSame(6380, $config->redis['port']);
        $this->assertSame(1, $config->redis['timeout']);   // fail fast to the file backup
    }

    public function testPredisHandlerIsTheAtomicSubclass(): void
    {
        // The rate limiter's per-key window counter must be atomic on Redis, so the
        // 'predis' alias resolves to our AtomicPredisHandler (a drop-in PredisHandler
        // that adds incrementWindow()). Guards against the alias regressing to the
        // stock handler, which would silently drop the limiter back to a racy
        // read-modify-write under load.
        $config = new Cache();

        $this->assertSame(AtomicPredisHandler::class, $config->validHandlers['predis']);
        $this->assertTrue(is_subclass_of(AtomicPredisHandler::class, PredisHandler::class));
        $this->assertTrue(method_exists(AtomicPredisHandler::class, 'incrementWindow'));
    }
}

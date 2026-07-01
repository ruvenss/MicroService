<?php

declare(strict_types=1);

use App\Libraries\Maintenance\Pruner;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Retention;

/**
 * Retention pruning of the transient operational tables. Verifies old/expired
 * rows are purged, recent rows are kept, and the compliance/recycle-bin tables
 * (audit_log, archived_records) are never touched.
 *
 * @internal
 */
final class MaintenancePruneTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $refresh   = true;

    private function db()
    {
        return db_connect();
    }

    private function daysAgo(int $days): string
    {
        return date('Y-m-d H:i:s', time() - $days * 86400);
    }

    /** @param array<string, mixed> $overrides */
    private function idempotency(array $overrides): void
    {
        $this->db()->table('idempotency_keys')->insert($overrides + [
            'idem_key'        => bin2hex(random_bytes(4)),
            'method'          => 'POST',
            'path'            => '/products',
            'request_hash'    => str_repeat('a', 64),
            'response_status' => 201,
            'response_type'   => 'application/json',
        ]);
    }

    public function testPrunesExpiredIdempotencyKeysOnly(): void
    {
        $this->idempotency(['created_at' => $this->daysAgo(2), 'expires_at' => $this->daysAgo(1)]);        // expired
        $this->idempotency(['created_at' => $this->daysAgo(0), 'expires_at' => date('Y-m-d H:i:s', time() + 3600)]); // live

        $this->assertSame(1, Pruner::pruneExpiredIdempotencyKeys());
        $this->assertSame(1, $this->db()->table('idempotency_keys')->countAllResults());
    }

    public function testPrunesOldAccessLogsButKeepsRecent(): void
    {
        $db = $this->db();
        $db->table('api_request_log')->insert(['method' => 'GET', 'path' => '/old', 'status' => 200, 'latency_ms' => 1, 'created_at' => $this->daysAgo(40)]);
        $db->table('api_request_log')->insert(['method' => 'GET', 'path' => '/new', 'status' => 200, 'latency_ms' => 1, 'created_at' => $this->daysAgo(1)]);

        $config                 = new Retention();
        $config->accessLogDays  = 30;

        $counts = Pruner::run($config);
        $this->assertSame(1, $counts['api_request_log']);
        $this->assertSame(1, $db->table('api_request_log')->countAllResults());
    }

    public function testPrunesDeliveredWebhooksOnly(): void
    {
        $db   = $this->db();
        $base = ['event' => 'products.afterCreate', 'resource' => 'products', 'target_url' => 'https://x', 'payload_json' => '{}', 'signature' => ''];
        $db->table('webhook_outbox')->insert($base + ['status' => 'delivered', 'created_at' => $this->daysAgo(30), 'delivered_at' => $this->daysAgo(10)]); // old delivered
        $db->table('webhook_outbox')->insert($base + ['status' => 'delivered', 'created_at' => $this->daysAgo(1), 'delivered_at' => $this->daysAgo(1)]);   // recent delivered
        $db->table('webhook_outbox')->insert($base + ['status' => 'failed', 'created_at' => $this->daysAgo(30), 'delivered_at' => null]);                  // never delivered

        $this->assertSame(1, Pruner::pruneDeliveredWebhooks(7));
        $this->assertSame(2, $db->table('webhook_outbox')->countAllResults());
    }

    public function testDryRunReportsButChangesNothing(): void
    {
        $this->idempotency(['created_at' => $this->daysAgo(2), 'expires_at' => $this->daysAgo(1)]);

        $this->assertSame(1, Pruner::pruneExpiredIdempotencyKeys(true));
        $this->assertSame(1, $this->db()->table('idempotency_keys')->countAllResults());
    }

    public function testAuditAndArchiveAreNeverPruned(): void
    {
        $db = $this->db();
        $db->table('audit_log')->insert(['action' => 'create', 'resource' => 'products', 'record_id' => '1', 'after_json' => '{}', 'created_at' => $this->daysAgo(999)]);
        $db->table('archived_records')->insert(['resource' => 'products', 'source_table' => 'products', 'record_id' => '1', 'payload_json' => '{}', 'deleted_at' => $this->daysAgo(999)]);

        Pruner::run(new Retention());

        $this->assertSame(1, $db->table('audit_log')->countAllResults());
        $this->assertSame(1, $db->table('archived_records')->countAllResults());
    }

    public function testCommandRunsAndPrunes(): void
    {
        $this->idempotency(['created_at' => $this->daysAgo(2), 'expires_at' => $this->daysAgo(1)]);

        ob_start();
        command('maintenance:prune');
        ob_end_clean();

        $this->assertSame(0, $this->db()->table('idempotency_keys')->countAllResults());
    }
}

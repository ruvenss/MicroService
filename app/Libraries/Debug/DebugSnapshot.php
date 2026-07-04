<?php

declare(strict_types=1);

namespace App\Libraries\Debug;

use App\Libraries\ResourceRegistry;
use App\Models\ApiKeyModel;
use App\Models\ApiRequestLogModel;
use CodeIgniter\CodeIgniter;
use Config\Database;
use Throwable;

/**
 * Assembles the live snapshot rendered by the `/ms_debug` development dashboard.
 *
 * This is a DEVELOPMENT-ONLY introspection aid (ARCHITECTURE §3 — framework
 * change, not a business feature): the controller that exposes it is registered
 * and self-guards ONLY when ENVIRONMENT === 'development', and this library is
 * built so that even if it were reached elsewhere it never emits a secret. Every
 * panel is assembled by explicit field allow-list — the API-key secret hashes
 * (`secret_hash`, `secret_hash_previous`), the DB password, and the webhook
 * secret are never read into the payload. Each collector is wrapped so a missing
 * table or a downed dependency degrades that panel to an `error` note instead of
 * blanking the whole dashboard.
 */
final class DebugSnapshot
{
    /** Default number of recent access-log rows surfaced in the "requests" panel. */
    public const REQUESTS_LIMIT = 25;

    public function __construct(private readonly int $requestsLimit = self::REQUESTS_LIMIT)
    {
    }

    /**
     * The complete snapshot the dashboard polls. Every value here is safe to
     * render in a browser and to serialise to JSON.
     *
     * @return array<string, mixed>
     */
    public function full(): array
    {
        return [
            'time'         => date('Y-m-d\TH:i:s\Z'),
            'environment'  => ENVIRONMENT,
            'runtime'      => $this->guard(fn (): array => $this->runtime()),
            'health'       => $this->guard(fn (): array => $this->health()),
            'requests'     => $this->guard(fn (): array => $this->requests()),
            'resources'    => $this->guard(fn (): array => $this->resources()),
            'routes'       => $this->guard(fn (): array => $this->routes()),
            'keys'         => $this->guard(fn (): array => $this->keys()),
            'webhooks'     => $this->guard(fn (): array => $this->webhooks()),
            'idempotency'  => $this->guard(fn (): array => $this->idempotency()),
        ];
    }

    /**
     * Interpreter / engine facts — versions, memory, and the OPcache/JIT posture.
     *
     * @return array<string, mixed>
     */
    private function runtime(): array
    {
        $opcache = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;

        return [
            'php_version'     => PHP_VERSION,
            'ci_version'      => CodeIgniter::CI_VERSION,
            'zend_threads'    => ZEND_THREAD_SAFE, // ZTS build per the target stack
            'memory_used_mb'  => round(memory_get_usage(true) / 1048576, 1),
            'memory_peak_mb'  => round(memory_get_peak_usage(true) / 1048576, 1),
            'memory_limit'    => ini_get('memory_limit'),
            'opcache_enabled' => is_array($opcache) ? (bool) ($opcache['opcache_enabled'] ?? false) : false,
            'jit_enabled'     => is_array($opcache) ? (bool) ($opcache['jit']['enabled'] ?? false) : false,
        ];
    }

    /**
     * Live dependency reachability + the active cache backend. Mirrors the
     * readiness probe's intent (DB `SELECT 1`, a cache round-trip) but is scoped
     * to the dashboard so it never disturbs the health endpoint's per-replica cache.
     *
     * @return array<string, mixed>
     */
    private function health(): array
    {
        $configuredHandler = config('Cache')->handler;
        $redisConfigured   = $configuredHandler === 'predis' || $configuredHandler === 'redis';

        return [
            'database'          => $this->pingDatabase() ? 'up' : 'down',
            'cache_handler'     => $configuredHandler,
            // 'predis'/'redis' configured but the probe fails ⇒ the app is running on
            // its transparent file-cache fallback (ARCHITECTURE — Redis-outage degrade).
            'cache'             => $this->pingCache() ? 'up' : 'down',
            'redis_configured'  => $redisConfigured,
            'file_fallback'     => $redisConfigured && ! $this->pingCache(),
        ];
    }

    private function pingDatabase(): bool
    {
        try {
            Database::connect()->query('SELECT 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function pingCache(): bool
    {
        try {
            $cache = service('cache');
            $probe = 'ms_debug_' . bin2hex(random_bytes(4));
            $cache->save($probe, '1', 2);
            $ok = $cache->get($probe) === '1';
            $cache->delete($probe);

            return $ok;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The most recent access-log rows — the panel that visibly ticks as traffic
     * flows through the service.
     *
     * @return list<array<string, mixed>>
     */
    private function requests(): array
    {
        return (new ApiRequestLogModel())
            ->orderBy('id', 'DESC')
            ->findAll($this->requestsLimit);
    }

    /**
     * Declared resources (core + plugin), each with its exposure allow-lists.
     *
     * @return list<array<string, mixed>>
     */
    private function resources(): array
    {
        $registry = ResourceRegistry::instance();
        $out      = [];

        foreach ($registry->slugs() as $slug) {
            $def = $registry->get($slug);
            if ($def === null) {
                continue;
            }

            $out[] = [
                'slug'       => $slug,
                'table'      => $def->table,
                'primaryKey' => $def->primaryKey,
                'filterable' => $def->filterable,
                'sortable'   => $def->sortable,
            ];
        }

        return $out;
    }

    /**
     * The registered route table (verb + URI pattern + handler). Read-only; taken
     * from the same RouteCollection the router used for this request.
     *
     * @return list<array{method: string, from: string, to: string}>
     */
    private function routes(): array
    {
        $collection = service('routes');
        $out        = [];

        foreach (['get', 'post', 'put', 'patch', 'delete', 'head'] as $verb) {
            foreach ($collection->getRoutes($verb) as $from => $to) {
                $out[] = [
                    'method' => strtoupper($verb),
                    'from'   => $from,
                    'to'     => is_string($to) ? $to : '(closure)',
                ];
            }
        }

        return $out;
    }

    /**
     * API-key METADATA only — prefix, label, status, scopes, limits, last use.
     * The secret hashes are never selected: a leaked dashboard cannot become a
     * source of key material.
     *
     * @return list<array<string, mixed>>
     */
    private function keys(): array
    {
        $model = new ApiKeyModel();
        $rows  = $model->orderBy('id', 'ASC')->findAll();
        $out   = [];

        foreach ($rows as $row) {
            $out[] = [
                'prefix'       => $row['prefix'],
                'name'         => $row['name'],
                'status'       => $row['status'],
                'scopes'       => $model->scopesFor((int) $row['id']),
                'rate_limit'   => $row['rate_limit'],
                'expires_at'   => $row['expires_at'] ?? null,
                'last_used_at' => $row['last_used_at'] ?? null,
                // Deliberately NOT included: secret_hash, secret_hash_previous.
            ];
        }

        return $out;
    }

    /**
     * Outbox delivery posture: how many events sit in each lifecycle state.
     *
     * @return array<string, int>
     */
    private function webhooks(): array
    {
        $db     = Database::connect();
        $counts = [];

        $rows = $db->table('webhook_outbox')
            ->select('status, COUNT(*) AS n')
            ->groupBy('status')
            ->get()
            ->getResultArray();

        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * How many idempotency claims are currently on record.
     *
     * @return array<string, int>
     */
    private function idempotency(): array
    {
        return ['total' => (int) Database::connect()->table('idempotency_keys')->countAllResults()];
    }

    /**
     * Run a collector, converting any failure into a compact error note so one
     * unavailable dependency never blanks the whole dashboard.
     *
     * @param callable():mixed $collector
     *
     * @return mixed
     */
    private function guard(callable $collector)
    {
        try {
            return $collector();
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }
}

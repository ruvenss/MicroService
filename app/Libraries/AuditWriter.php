<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\AuditLogModel;
use CodeIgniter\HTTP\IncomingRequest;

/**
 * Writes one audit_log row per mutation. Call inside the same DB transaction as
 * the change so the change and its audit record commit or roll back together.
 *
 * Snapshots should already have hidden/secret fields stripped by the caller.
 */
final class AuditWriter
{
    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public static function record(string $action, ?string $resource, ?string $recordId, ?array $before, ?array $after): void
    {
        $changed = null;
        if ($before !== null && $after !== null) {
            $changed = [];
            foreach ($after as $key => $value) {
                if (! array_key_exists($key, $before) || $before[$key] !== $value) {
                    $changed[] = $key;
                }
            }
        }

        $request = service('request');

        (new AuditLogModel())->insert([
            'request_id'   => RequestContext::id(),
            'api_key_id'   => AuthContext::keyId(),
            'action'       => $action,
            'resource'     => $resource,
            'record_id'    => $recordId,
            'before_json'  => $before !== null ? json_encode($before) : null,
            'after_json'   => $after !== null ? json_encode($after) : null,
            'changed_json' => $changed !== null ? json_encode($changed) : null,
            'ip'           => $request instanceof IncomingRequest ? $request->getIPAddress() : null,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
    }
}

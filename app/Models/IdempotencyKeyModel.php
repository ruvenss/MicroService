<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;
use Throwable;

class IdempotencyKeyModel extends Model
{
    /**
     * A pending claim (in-flight request) is marked by an impossible HTTP status,
     * so no schema change is needed: 0 = "claimed, response not recorded yet".
     */
    public const PENDING_STATUS = 0;

    protected $table         = 'idempotency_keys';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'api_key_id',
        'idem_key',
        'method',
        'path',
        'request_hash',
        'response_status',
        'response_type',
        'response_body',
        'created_at',
        'expires_at',
    ];

    /**
     * The record for this (api key, idempotency key), regardless of expiry — the
     * caller decides how to treat an expired or pending row.
     *
     * @return array<string, mixed>|null
     */
    public function findForClaim(?int $apiKeyId, string $idemKey): ?array
    {
        return $this->where('api_key_id', $apiKeyId)
            ->where('idem_key', $idemKey)
            ->first();
    }

    /**
     * Atomically claim the key by inserting a *pending* row. The unique index on
     * (api_key_id, idem_key) means exactly one of N concurrent requests wins the
     * insert; the losers get a duplicate-key error and this returns null (the
     * caller answers 409 "in progress"). Returns the new row id on success.
     *
     * @return int|null the claim row id, or null if another request already holds it
     */
    public function claim(?int $apiKeyId, string $idemKey, string $method, string $path, string $hash, int $ttl): ?int
    {
        $now = date('Y-m-d H:i:s');

        try {
            $this->insert([
                'api_key_id'      => $apiKeyId,
                'idem_key'        => $idemKey,
                'method'          => $method,
                'path'            => $path,
                'request_hash'    => $hash,
                'response_status' => self::PENDING_STATUS,
                'response_type'   => '',
                'response_body'   => null,
                'created_at'      => $now,
                'expires_at'      => date('Y-m-d H:i:s', time() + $ttl),
            ]);
        } catch (Throwable) {
            // Duplicate unique key — a concurrent request already claimed it.
            return null;
        }

        return (int) $this->getInsertID();
    }

    /**
     * Finalise the claim with the real response so future retries replay it.
     */
    public function complete(int $id, int $status, string $type, string $body): void
    {
        $this->update($id, [
            'response_status' => $status,
            'response_type'   => $type,
            'response_body'   => $body,
        ]);
    }

    /**
     * Drop a claim row (a failed/non-2xx request, or a dead/expired claim being
     * taken over), so the operation stays retryable.
     */
    public function release(int $id): void
    {
        $this->delete($id);
    }
}

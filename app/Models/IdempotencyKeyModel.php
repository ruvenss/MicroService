<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class IdempotencyKeyModel extends Model
{
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
     * Find an unexpired record for this (api key, idempotency key).
     *
     * @return array<string, mixed>|null
     */
    public function findValid(?int $apiKeyId, string $idemKey): ?array
    {
        return $this->where('api_key_id', $apiKeyId)
            ->where('idem_key', $idemKey)
            ->where('expires_at >=', date('Y-m-d H:i:s'))
            ->first();
    }
}

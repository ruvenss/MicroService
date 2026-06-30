<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * API keys. Only the SHA-256 hash of each secret is stored; lookups are by the
 * public `prefix`, after which the secret hash is compared in constant time.
 */
class ApiKeyModel extends Model
{
    protected $table         = 'api_keys';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['prefix', 'secret_hash', 'name', 'status', 'rate_limit', 'expires_at', 'last_used_at'];

    /**
     * Fetch an active, unexpired key row by its public prefix.
     *
     * @return array<string, mixed>|null
     */
    public function findActiveByPrefix(string $prefix): ?array
    {
        $row = $this->where('prefix', $prefix)->where('status', 'active')->first();
        if ($row === null) {
            return null;
        }

        if (! empty($row['expires_at']) && strtotime((string) $row['expires_at']) < time()) {
            return null;
        }

        return $row;
    }

    /**
     * @return list<string>
     */
    public function scopesFor(int $keyId): array
    {
        $rows = $this->db->table('api_key_scopes')
            ->select('scope')
            ->where('api_key_id', $keyId)
            ->get()
            ->getResultArray();

        return array_values(array_map(static fn (array $r): string => (string) $r['scope'], $rows));
    }
}

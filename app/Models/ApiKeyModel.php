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
    protected $allowedFields = [
        'prefix', 'secret_hash', 'secret_hash_previous', 'previous_expires_at',
        'name', 'status', 'rate_limit', 'expires_at', 'last_used_at',
    ];

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
     * Constant-time check of a presented secret against a key row: the current
     * hash always, and the previous hash while its rotation grace window is open.
     *
     * @param array<string, mixed> $key a row from findActiveByPrefix()
     */
    public function verifySecret(array $key, string $secret): bool
    {
        $candidate = hash('sha256', $secret);

        if (hash_equals((string) $key['secret_hash'], $candidate)) {
            return true;
        }

        $previous = (string) ($key['secret_hash_previous'] ?? '');
        $expires  = $key['previous_expires_at'] ?? null;
        if ($previous !== '' && $expires !== null && strtotime((string) $expires) > time()) {
            return hash_equals($previous, $candidate);
        }

        return false;
    }

    /**
     * Rotate an active key's secret, keeping the old one valid for a grace window.
     * Returns the new plaintext secret (shown once) + the grace deadline, or null
     * if no active key has that prefix.
     *
     * @return array{prefix: string, secret: string, previous_expires_at: string}|null
     */
    public function rotate(string $prefix, int $graceHours): ?array
    {
        $key = $this->findActiveByPrefix($prefix);
        if ($key === null) {
            return null;
        }

        $newSecret = bin2hex(random_bytes(24));
        $graceUntil = date('Y-m-d H:i:s', time() + max(0, $graceHours) * 3600);

        $this->update((int) $key['id'], [
            'secret_hash'          => hash('sha256', $newSecret),
            'secret_hash_previous' => $key['secret_hash'],
            'previous_expires_at'  => $graceUntil,
        ]);

        return ['prefix' => $prefix, 'secret' => $newSecret, 'previous_expires_at' => $graceUntil];
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

<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\ApiKeyModel;

/**
 * Mints API keys directly in the (transactional) test database so feature tests
 * can authenticate. Requires DatabaseTestTrait with the App migrations.
 */
trait AuthTestTrait
{
    /**
     * @param list<string> $scopes
     *
     * @return string the full bearer token (prefix.secret)
     */
    protected function makeKey(array $scopes, string $status = 'active', ?string $expiresAt = null, ?int $rateLimit = null): string
    {
        $prefix = bin2hex(random_bytes(6));
        $secret = bin2hex(random_bytes(24));

        $model = new ApiKeyModel();
        $id    = (int) $model->insert([
            'prefix'      => $prefix,
            'secret_hash' => hash('sha256', $secret),
            'name'        => 'test',
            'status'      => $status,
            'rate_limit'  => $rateLimit,
            'expires_at'  => $expiresAt,
        ], true);

        foreach ($scopes as $scope) {
            $model->db->table('api_key_scopes')->insert(['api_key_id' => $id, 'scope' => $scope]);
        }

        return $prefix . '.' . $secret;
    }

    /**
     * @param list<string> $scopes
     *
     * @return array<string, string>
     */
    protected function authHeaders(array $scopes): array
    {
        return ['Authorization' => 'Bearer ' . $this->makeKey($scopes)];
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Libraries\AuthContext;
use App\Libraries\ResponseEnvelope;
use App\Models\ApiKeyModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Introspection of the authenticated API key: what the caller can do. Lets an
 * n8n workflow verify connectivity and its granted scopes before running. Never
 * returns the secret; only the caller's own key metadata.
 */
class Me extends BaseController
{
    public function index(): ResponseInterface
    {
        $key = (new ApiKeyModel())->find(AuthContext::keyId());

        return $this->response->setJSON(ResponseEnvelope::wrap([
            'name'       => $key['name'] ?? null,
            'scopes'     => AuthContext::scopes(),
            'rateLimit'  => isset($key['rate_limit']) ? (int) $key['rate_limit'] : null,
            'expiresAt'  => $key['expires_at'] ?? null,
            'lastUsedAt' => $key['last_used_at'] ?? null,
        ]));
    }
}

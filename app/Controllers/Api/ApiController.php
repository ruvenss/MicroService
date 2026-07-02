<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Libraries\ApiProblem;
use App\Libraries\Authorization;
use App\Libraries\AuthContext;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Shared helpers for meta/admin API controllers: neutral problem responses and
 * explicit scope checks (used where the scope isn't the {resource}:{action} the
 * RequirePermission filter derives from the path).
 */
abstract class ApiController extends BaseController
{
    /**
     * @param array<string, mixed> $extra
     */
    protected function problem(int $status, ?string $detail = null, array $extra = []): ResponseInterface
    {
        return ApiProblem::respond($status, $detail, $extra);
    }

    protected function requireScope(string $scope): ?ResponseInterface
    {
        if (Authorization::satisfies(AuthContext::scopes(), $scope)) {
            return null;
        }

        return $this->problem(403, 'The API key lacks the required scope for this operation.');
    }

    /**
     * Refuse a pathologically deep offset (`(page-1) * perPage > MAX_OFFSET`) with a 400
     * *before* the COUNT/scan — see BaseController::MAX_OFFSET. `$steer` names the
     * cheaper keyset alternative for this endpoint (e.g. `?sinceId=`).
     */
    protected function guardDeepOffset(int $page, int $perPage, string $steer): ?ResponseInterface
    {
        if (($page - 1) * $perPage > self::MAX_OFFSET) {
            return $this->problem(400, 'Result window is too deep for offset pagination. ' . $steer);
        }

        return null;
    }
}

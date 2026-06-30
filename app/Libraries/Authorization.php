<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Permission scope grammar: `{resource}:{action}` where action ∈ {read, write,
 * delete}. Wildcards are supported on either side (`products:*`, `*:read`, `*`).
 *
 * HTTP method maps to an action: GET→read, POST/PUT/PATCH→write, DELETE→delete.
 */
final class Authorization
{
    /**
     * @var array<string, string>
     */
    private const METHOD_ACTIONS = [
        'GET'    => 'read',
        'HEAD'   => 'read',
        'POST'   => 'write',
        'PUT'    => 'write',
        'PATCH'  => 'write',
        'DELETE' => 'delete',
    ];

    public static function actionForMethod(string $method): string
    {
        return self::METHOD_ACTIONS[strtoupper($method)] ?? 'write';
    }

    public static function requiredScope(string $resource, string $method): string
    {
        return $resource . ':' . self::actionForMethod($method);
    }

    /**
     * Does any held scope satisfy the required `{resource}:{action}` scope,
     * accounting for wildcards?
     *
     * @param list<string> $heldScopes
     */
    public static function satisfies(array $heldScopes, string $required): bool
    {
        [$resource, $action] = array_pad(explode(':', $required, 2), 2, '');

        $acceptable = [
            $required,                 // exact, e.g. products:read
            $resource . ':*',          // all actions on the resource
            '*:' . $action,            // this action on all resources
            '*',                       // everything
            '*:*',                     // everything (explicit form)
        ];

        foreach ($heldScopes as $scope) {
            if (in_array($scope, $acceptable, true)) {
                return true;
            }
        }

        return false;
    }
}

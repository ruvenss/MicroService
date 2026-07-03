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

    /**
     * May a key holding these scopes touch this resource at all — read, write, or
     * delete it? Used to scope resource discovery to what the key can actually use
     * (least privilege: a limited or leaked key never learns the names/schemas of
     * resources it has no scope for).
     *
     * @param list<string> $heldScopes
     */
    public static function permitsResource(array $heldScopes, string $resource): bool
    {
        foreach (['read', 'write', 'delete'] as $action) {
            if (self::satisfies($heldScopes, $resource . ':' . $action)) {
                return true;
            }
        }

        return false;
    }
}

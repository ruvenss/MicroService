<?php

declare(strict_types=1);

namespace App\Libraries\Docs;

use App\Libraries\ResourceRegistry;

/**
 * Builds an OpenAPI 3.1 document from the endpoint catalog.
 */
final class OpenApiGenerator
{
    /**
     * @return array<string, mixed>
     */
    public static function generate(?ResourceRegistry $registry = null, string $serverUrl = 'http://localhost:8080'): array
    {
        $paths = [];

        foreach (EndpointCatalog::all($registry) as $ep) {
            $path   = $ep['path'];
            $method = strtolower($ep['method']);

            $paths[$path][$method] = self::operation($ep);
        }

        ksort($paths);

        return [
            'openapi' => '3.1.0',
            'info'    => [
                'title'       => 'MicroService API',
                'version'     => '1.0.0',
                'description' => "Generated from the resource registry. Bearer API-key auth on all "
                    . "/api/v1/* except health. Success: `{data, meta}`. Errors: RFC 9457 problem+json. "
                    . "Every response carries X-Request-Id; rate limits via X-RateLimit-* / 429. "
                    . "Reads carry an ETag — pass it back as If-None-Match for a 304 Not Modified. "
                    . "Every GET endpoint also answers HEAD (same status/headers, no body).",
            ],
            'servers'    => [['url' => $serverUrl]],
            'security'   => [['bearerAuth' => []]],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'API key as `prefix.secret`.'],
                ],
                'schemas' => [
                    'Problem' => [
                        'type'        => 'object',
                        'description' => 'RFC 9457 problem detail.',
                        'properties'  => [
                            'type'      => ['type' => 'string'],
                            'title'     => ['type' => 'string'],
                            'status'    => ['type' => 'integer'],
                            'detail'    => ['type' => 'string'],
                            'requestId' => ['type' => 'string'],
                            'errors'    => ['type' => 'object', 'additionalProperties' => true],
                        ],
                    ],
                ],
            ],
            'paths' => $paths,
        ];
    }

    /**
     * @param array<string, mixed> $ep
     *
     * @return array<string, mixed>
     */
    private static function operation(array $ep): array
    {
        $op = [
            'tags'        => [$ep['tag']],
            'operationId' => $ep['operationId'],
            'summary'     => $ep['summary'] . ($ep['scope'] !== null ? " (scope: {$ep['scope']})" : ''),
            'security'    => $ep['auth'] ? [['bearerAuth' => []]] : [],
        ];

        $params = [];
        foreach ($ep['pathParams'] as $name) {
            $params[] = ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']];
        }
        foreach ($ep['query'] as $q) {
            $params[] = ['name' => $q['name'], 'in' => 'query', 'required' => false, 'description' => $q['description'], 'schema' => ['type' => 'string']];
        }
        if (in_array($ep['method'], ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $params[] = [
                'name'        => 'Idempotency-Key',
                'in'          => 'header',
                'required'    => false,
                'description' => 'Optional. A retry with the same key + request replays the first response (safe for n8n retries).',
                'schema'      => ['type' => 'string'],
            ];
        }
        if (in_array($ep['method'], ['PUT', 'PATCH', 'DELETE'], true) && in_array('id', $ep['pathParams'], true)) {
            $params[] = [
                'name'        => 'If-Match',
                'in'          => 'header',
                'required'    => false,
                'description' => 'Optional. Pass the ETag you fetched; the write is refused with 412 if the record changed since (optimistic concurrency — prevents lost updates).',
                'schema'      => ['type' => 'string'],
            ];
        }
        if ($ep['method'] === 'GET') {
            $params[] = [
                'name'        => 'If-None-Match',
                'in'          => 'header',
                'required'    => false,
                'description' => 'Optional. Pass a prior ETag; the server replies 304 Not Modified (empty body) when nothing changed.',
                'schema'      => ['type' => 'string'],
            ];
        }
        if ($params !== []) {
            $op['parameters'] = $params;
        }

        if (is_array($ep['body'])) {
            $properties = [];
            $required   = [];
            foreach ($ep['body'] as $field => $meta) {
                $properties[$field] = ['type' => $meta['type']];
                if (! empty($meta['enum'])) {
                    $properties[$field]['enum'] = $meta['enum'];
                }
                if (isset($meta['example'])) {
                    $properties[$field]['example'] = $meta['example'];
                }
                if ($meta['required']) {
                    $required[] = $field;
                }
            }
            $schema = ['type' => 'object', 'properties' => $properties];
            if ($required !== []) {
                $schema['required'] = $required;
            }
            $op['requestBody'] = [
                'required' => true,
                'content'  => ['application/json' => ['schema' => $schema]],
            ];
        } elseif (is_string($ep['bodyExample'] ?? null)) {
            $decoded = json_decode($ep['bodyExample'], true);
            $schema  = is_array($decoded) && array_is_list($decoded)
                ? ['type' => 'array', 'items' => ['type' => 'object']]
                : ['type' => 'object'];
            $op['requestBody'] = [
                'required' => true,
                'content'  => ['application/json' => ['schema' => $schema, 'example' => $decoded]],
            ];
        }

        $op['responses'] = self::responses($ep);

        return $op;
    }

    /**
     * @param array<string, mixed> $ep
     *
     * @return array<string, mixed>
     */
    private static function responses(array $ep): array
    {
        $responses = [];

        $successHeaders = self::responseHeaders($ep);

        if ($ep['successKind'] === 'none') {
            $responses[(string) $ep['success']] = ['description' => 'No content', 'headers' => $successHeaders];
        } elseif ($ep['successKind'] === 'meta') {
            $envelope = ['type' => 'object', 'properties' => ['meta' => ['type' => 'object']]];
            $responses[(string) $ep['success']] = [
                'description' => 'Success',
                'headers'     => $successHeaders,
                'content'     => ['application/json' => ['schema' => $envelope]],
            ];
        } else {
            $data = $ep['successKind'] === 'collection'
                ? ['type' => 'array', 'items' => ['type' => 'object']]
                : ['type' => 'object'];
            $envelope = ['type' => 'object', 'properties' => ['data' => $data, 'meta' => ['type' => 'object']]];
            $responses[(string) $ep['success']] = [
                'description' => 'Success',
                'headers'     => $successHeaders,
                'content'     => ['application/json' => ['schema' => $envelope]],
            ];
        }

        if ($ep['method'] === 'GET') {
            $responses['304'] = ['description' => 'Not Modified — the ETag matched If-None-Match.'];
        }

        $isWrite = in_array($ep['method'], ['POST', 'PUT', 'PATCH', 'DELETE'], true);

        $hasBody = is_array($ep['body']) || is_string($ep['bodyExample'] ?? null);
        $errors  = $ep['auth'] ? [400, 401, 403, 404, 422, 429] : [400, 404, 429];
        if (! $hasBody) {
            $errors = array_values(array_diff($errors, [422]));
        } else {
            $errors[] = 413; // body-carrying requests can exceed the size limit
        }
        if ($isWrite && $ep['auth']) {
            // A write may accept an Idempotency-Key whose original request is still in
            // flight, and a DELETE may be vetoed by a plugin (resource.beforeDelete).
            $errors[] = 409;
        }
        if (in_array($ep['method'], ['PUT', 'PATCH', 'DELETE'], true) && in_array('id', $ep['pathParams'], true)) {
            $errors[] = 412; // If-Match optimistic-concurrency precondition can fail
        }
        if (str_ends_with((string) $ep['path'], '/health')) {
            $errors[] = 503; // readiness degraded (database or cache unreachable)
        }
        $errors = array_values(array_unique($errors));
        sort($errors);

        foreach ($errors as $code) {
            $response = [
                'description' => self::ERROR_DESCRIPTIONS[$code] ?? 'Error',
                'content'     => ['application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/Problem']]],
            ];
            if ($code === 429) {
                $response['headers'] = [
                    'Retry-After'           => self::header('Seconds to wait before retrying.'),
                    'X-RateLimit-Limit'     => self::header('Requests allowed per window.'),
                    'X-RateLimit-Remaining' => self::header('Requests remaining in the current window.'),
                    'X-RateLimit-Reset'     => self::header('Epoch second when the window resets.'),
                ];
            } elseif ($code === 409 || $code === 503) {
                // Both may carry Retry-After (an in-progress Idempotency-Key; a degraded
                // readiness cached for its TTL), so a client can back off precisely.
                $response['headers'] = ['Retry-After' => self::header('Seconds to wait before retrying.')];
            }
            $responses[(string) $code] = $response;
        }

        return $responses;
    }

    /** Human-readable descriptions for the error codes with specific semantics. */
    private const ERROR_DESCRIPTIONS = [
        409 => 'Conflict — an Idempotency-Key whose original request is still in progress, or a delete a plugin vetoed.',
        412 => 'Precondition Failed — the If-Match ETag did not match the current resource.',
        413 => 'Payload Too Large.',
        422 => 'Unprocessable Entity — validation failed (see the errors member).',
        429 => 'Too Many Requests — rate limit exceeded.',
        503 => 'Service Unavailable — readiness degraded (database or cache unreachable).',
    ];

    /**
     * Standard success-response headers: the correlation id always, per-key
     * rate-limit signalling on authenticated endpoints (so n8n can self-throttle),
     * and the ETag validator on reads.
     *
     * @param array<string, mixed> $ep
     *
     * @return array<string, array{description: string, schema: array{type: string}}>
     */
    private static function responseHeaders(array $ep): array
    {
        $headers = ['X-Request-Id' => self::header('Correlation id (echoes an inbound one).')];

        if ($ep['auth']) {
            $headers['X-RateLimit-Limit']     = self::header('Requests allowed per window.');
            $headers['X-RateLimit-Remaining'] = self::header('Requests remaining in the current window.');
            $headers['X-RateLimit-Reset']     = self::header('Epoch second when the window resets.');
        }

        if ($ep['method'] === 'GET') {
            $headers['ETag'] = self::header('Validator for conditional requests (send back as If-None-Match).');
        }

        return $headers;
    }

    /**
     * @return array{description: string, schema: array{type: string}}
     */
    private static function header(string $description): array
    {
        return ['description' => $description, 'schema' => ['type' => 'string']];
    }
}

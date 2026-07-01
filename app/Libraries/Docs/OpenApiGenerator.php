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
                    . "Every response carries X-Request-Id; rate limits via X-RateLimit-* / 429.",
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
        if ($params !== []) {
            $op['parameters'] = $params;
        }

        if (is_array($ep['body'])) {
            $properties = [];
            $required   = [];
            foreach ($ep['body'] as $field => $meta) {
                $properties[$field] = ['type' => $meta['type']];
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

        if ($ep['successKind'] === 'none') {
            $responses[(string) $ep['success']] = ['description' => 'No content'];
        } else {
            $data = $ep['successKind'] === 'collection'
                ? ['type' => 'array', 'items' => ['type' => 'object']]
                : ['type' => 'object'];
            $envelope = ['type' => 'object', 'properties' => ['data' => $data, 'meta' => ['type' => 'object']]];
            $responses[(string) $ep['success']] = [
                'description' => 'Success',
                'content'     => ['application/json' => ['schema' => $envelope]],
            ];
        }

        $errors = $ep['auth'] ? [400, 401, 403, 404, 422, 429] : [400, 404, 429];
        if (! is_array($ep['body'])) {
            $errors = array_values(array_diff($errors, [422]));
        }
        foreach ($errors as $code) {
            $responses[(string) $code] = [
                'description' => 'Error',
                'content'     => ['application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/Problem']]],
            ];
        }

        return $responses;
    }
}

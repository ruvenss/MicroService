<?php

declare(strict_types=1);

namespace App\Libraries\Docs;

use App\Libraries\ResourceRegistry;

/**
 * Builds a Postman v2.1.0 collection from the endpoint catalog: collection-level
 * bearer auth, one folder per tag, request bodies/params, and a test script that
 * captures created ids so a human can run create → show/update/delete in order.
 */
final class PostmanGenerator
{
    /**
     * @return array<string, mixed>
     */
    public static function generate(?ResourceRegistry $registry = null): array
    {
        $endpoints = EndpointCatalog::all($registry);

        $folders   = [];
        $variables = [
            ['key' => 'baseUrl', 'value' => 'http://localhost:8080', 'type' => 'string'],
            ['key' => 'apiKey', 'value' => '', 'type' => 'string'],
        ];
        $seenVars = [];

        foreach ($endpoints as $ep) {
            $idVar = self::idVar($ep);
            if ($idVar !== null && ! isset($seenVars[$idVar])) {
                $seenVars[$idVar] = true;
                $variables[]      = ['key' => $idVar, 'value' => '', 'type' => 'string'];
            }
            $folders[$ep['tag']][] = self::request($ep, $idVar);
        }

        $items = [];
        foreach ($folders as $tag => $requests) {
            $items[] = ['name' => $tag, 'item' => $requests];
        }

        return [
            'info' => [
                '_postman_id' => 'b1f6e2a0-0000-4a00-9000-microservice01',
                'name'        => 'MicroService API',
                'description' => "Generated from the resource registry (php spark docs:generate). Set the "
                    . "`baseUrl` and `apiKey` collection variables. Mint a key with `php spark key:create`. "
                    . "Bearer auth is inherited by every request except health. Success: {data, meta}; "
                    . "errors: RFC 9457 problem+json; rate limits via X-RateLimit-* / 429; reads carry an "
                    . "ETag (resend as If-None-Match for 304); responses carry no PHP/CodeIgniter/Apache fingerprint.",
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            ],
            'auth'     => ['type' => 'bearer', 'bearer' => [['key' => 'token', 'value' => '{{apiKey}}', 'type' => 'string']]],
            'variable' => $variables,
            'item'     => $items,
        ];
    }

    /**
     * @param array<string, mixed> $ep
     *
     * @return array<string, mixed>
     */
    private static function request(array $ep, ?string $idVar): array
    {
        $segments = array_values(array_filter(explode('/', $ep['path']), static fn (string $s): bool => $s !== ''));
        $segments = array_map(static fn (string $s): string => $s === '{id}' ? '{{' . $idVar . '}}' : $s, $segments);

        $url = [
            'raw'  => '{{baseUrl}}/' . implode('/', $segments),
            'host' => ['{{baseUrl}}'],
            'path' => $segments,
        ];

        $query = [];
        foreach ($ep['query'] as $q) {
            $query[] = ['key' => $q['name'], 'value' => '', 'description' => $q['description'], 'disabled' => true];
        }
        if ($query !== []) {
            $url['query']  = $query;
            $url['raw']   .= '?' . implode('&', array_map(static fn (array $q): string => $q['key'] . '=', $query));
        }

        $header  = [['key' => 'Accept', 'value' => 'application/json']];
        $request = [
            'method'      => $ep['method'],
            'header'      => $header,
            'url'         => $url,
            'description' => $ep['summary'] . ($ep['scope'] !== null ? " Scope: {$ep['scope']}." : ''),
        ];

        if (! $ep['auth']) {
            $request['auth'] = ['type' => 'noauth'];
        }

        if (in_array($ep['method'], ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $request['header'][] = [
                'key'         => 'Idempotency-Key',
                'value'       => '',
                'description' => 'Optional. Retries with the same key replay the first response (n8n-safe).',
                'disabled'    => true,
            ];
        }
        if ($ep['method'] === 'GET') {
            $request['header'][] = [
                'key'         => 'If-None-Match',
                'value'       => '',
                'description' => 'Optional. Paste a prior ETag to get 304 Not Modified when unchanged.',
                'disabled'    => true,
            ];
        }
        if (in_array($ep['method'], ['PUT', 'PATCH', 'DELETE'], true) && in_array('id', $ep['pathParams'], true)) {
            $request['header'][] = [
                'key'         => 'If-Match',
                'value'       => '',
                'description' => 'Optional. Paste the ETag you fetched; the write returns 412 if the record changed since (optimistic concurrency).',
                'disabled'    => true,
            ];
        }

        $bodyExample = $ep['bodyExample'] ?? null;
        if (is_array($ep['body'])) {
            $request['header'][] = ['key' => 'Content-Type', 'value' => 'application/json'];
            $request['body']     = ['mode' => 'raw', 'raw' => self::exampleBody($ep['body'])];
        } elseif (is_string($bodyExample)) {
            $request['header'][] = ['key' => 'Content-Type', 'value' => 'application/json'];
            $request['body']     = ['mode' => 'raw', 'raw' => $bodyExample];
        }

        $item = ['name' => $ep['summary'], 'request' => $request, 'response' => []];

        if ($ep['captureId'] === true && $idVar !== null) {
            // Capture the resource's actual primary key (not a hardcoded `id`), so
            // the create → show/update/delete chain works for any keyed resource.
            $pk = $ep['primaryKey'] ?? 'id';
            $item['event'] = [[
                'listen' => 'test',
                'script' => [
                    'type' => 'text/javascript',
                    'exec' => [
                        "if (pm.response.code === 201) {",
                        "    pm.collectionVariables.set('{$idVar}', pm.response.json().data.{$pk});",
                        '}',
                    ],
                ],
            ]];
        }

        return $item;
    }

    /**
     * @param array<string, array{type: string, required: bool, enum?: list<string>, example?: string|int|float}> $body
     */
    private static function exampleBody(array $body): string
    {
        $example = [];
        foreach ($body as $field => $meta) {
            // A valid, type-appropriate value (enum-aware) so the request works as-is.
            $example[$field] = $meta['example'] ?? match ($meta['type']) {
                'number'  => '0.00',
                'integer' => 0,
                default   => 'string',
            };
        }

        return (string) json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string, mixed> $ep
     */
    private static function idVar(array $ep): ?string
    {
        if (! in_array('id', $ep['pathParams'], true)) {
            return null;
        }
        if ($ep['resource'] !== null) {
            return $ep['resource'] . 'Id';
        }

        return str_contains($ep['path'], '_archive') ? 'archiveId' : 'id';
    }
}

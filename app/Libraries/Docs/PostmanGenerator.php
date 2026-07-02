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
            // A pre-request script may populate its own throwaway-id variable ({slug}DelId).
            if (isset($ep['postmanVar']) && ! isset($seenVars[$ep['postmanVar']])) {
                $seenVars[$ep['postmanVar']] = true;
                $variables[]                 = ['key' => $ep['postmanVar'], 'value' => '', 'type' => 'string'];
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

        // A Postman-specific body (e.g. bulk update targeting {{slugId}}) overrides the
        // shared readable example so the request runs against the captured row; OpenAPI
        // and Markdown keep the plain `bodyExample`.
        $bodyExample = $ep['postmanBodyExample'] ?? $ep['bodyExample'] ?? null;
        if (is_array($ep['body'])) {
            $request['header'][] = ['key' => 'Content-Type', 'value' => 'application/json'];
            $request['body']     = ['mode' => 'raw', 'raw' => self::exampleBody($ep['body'])];
        } elseif (is_string($bodyExample)) {
            $request['header'][] = ['key' => 'Content-Type', 'value' => 'application/json'];
            $request['body']     = ['mode' => 'raw', 'raw' => $bodyExample];
        }

        $item = ['name' => $ep['summary'], 'request' => $request, 'response' => []];

        $events = [];
        // Self-verifying test script: assert the documented success status + response
        // envelope first (so a human running the collection gets a green check per
        // endpoint, not just a status code), then capture the created id for the chain.
        $testLines = array_merge(self::assertionScript($ep), self::captureScript($ep) ?? []);
        if ($testLines !== []) {
            $events[] = ['listen' => 'test', 'script' => ['type' => 'text/javascript', 'exec' => $testLines]];
        }
        // A pre-request script (e.g. bulk delete creates a throwaway row to delete, so it
        // never removes the row the single-item chain uses).
        if (is_array($ep['postmanPrerequest'] ?? null)) {
            $events[] = ['listen' => 'prerequest', 'script' => ['type' => 'text/javascript', 'exec' => $ep['postmanPrerequest']]];
        }
        if ($events !== []) {
            $item['event'] = $events;
        }

        return $item;
    }

    /**
     * A self-verifying test script: assert the response status is a documented success
     * and the body carries the expected `{data}`/`{meta}` envelope. Turns the collection
     * into a smoke test a human (or `newman run`) can run against any deployment —
     * green/red per endpoint, not just a status code. Runs before the id-capture script.
     *
     * @param array<string, mixed> $ep
     *
     * @return list<string>
     */
    private static function assertionScript(array $ep): array
    {
        $codes = '[' . implode(', ', self::expectedCodes($ep)) . ']';

        $lines = [
            'pm.test(' . json_encode('status is a documented success', JSON_UNESCAPED_SLASHES) . ', function () {',
            "    pm.expect(pm.response.code).to.be.oneOf({$codes});",
            '});',
        ];

        // 204 No Content (delete) carries no body to shape-check.
        if ($ep['successKind'] === 'none') {
            return $lines;
        }

        // item → data object; collection → data array; meta varies (e.g. _me has a data
        // object, _resources a data array, bulk delete only a meta object) so accept
        // either envelope key.
        $shape = match ($ep['successKind']) {
            'collection' => "    pm.expect(body.data, 'data').to.be.an('array');",
            'item'       => "    pm.expect(body.data, 'data').to.be.an('object');",
            default      => "    pm.expect(body).to.have.any.keys('data', 'meta');",
        };

        return array_merge($lines, [
            'pm.test(' . json_encode('returns the success envelope', JSON_UNESCAPED_SLASHES) . ', function () {',
            '    var body = pm.response.json();',
            $shape,
            '});',
        ]);
    }

    /**
     * HTTP status codes that count as success for this endpoint's Postman example.
     *
     * @param array<string, mixed> $ep
     *
     * @return list<int>
     */
    private static function expectedCodes(array $ep): array
    {
        if ($ep['successKind'] === 'none') {
            return [204];
        }
        // The collection-level PUT is the upsert: its example creates a fresh row (201),
        // but the same endpoint returns 200 when the natural key already exists — both ok.
        if ($ep['method'] === 'PUT' && ! in_array('id', $ep['pathParams'], true)) {
            return [200, 201];
        }

        return [$ep['success']];
    }

    /**
     * The test script that populates an id collection variable so a human can run the
     * chained requests (show/update/delete/restore) without copying ids by hand:
     *
     *   - a **create** stashes the new record's id (always, on 201);
     *   - a **list** seeds the id from the first row *only if it is still empty*, so
     *     the chain is runnable straight after import even for resources with no
     *     create in the flow (notably the recycle bin: nothing else sets archiveId).
     *
     * @param array<string, mixed> $ep
     *
     * @return list<string>|null the script lines, or null when this endpoint feeds no id
     */
    private static function captureScript(array $ep): ?array
    {
        $var = self::idVarName($ep);
        if ($var === null) {
            return null;
        }
        $pk      = $ep['primaryKey'] ?? 'id';
        $hasIdIn = in_array('id', $ep['pathParams'], true);

        if ($ep['captureId'] === true) {
            return [
                'const ct = pm.response.headers.get("Content-Type") || "";',
                "if (pm.response.code === 201 && ct.indexOf('json') !== -1) {",
                '    const d = pm.response.json().data;',
                "    if (d && d.{$pk} !== undefined) { pm.collectionVariables.set('{$var}', d.{$pk}); }",
                '}',
            ];
        }

        if ($ep['method'] === 'GET' && $ep['successKind'] === 'collection' && ! $hasIdIn) {
            return [
                "if (pm.response.code === 200 && !pm.collectionVariables.get('{$var}')) {",
                '    const rows = (pm.response.json() || {}).data;',
                "    if (Array.isArray(rows) && rows.length && rows[0].{$pk} !== undefined) {",
                "        pm.collectionVariables.set('{$var}', rows[0].{$pk});",
                '    }',
                '}',
            ];
        }

        return null;
    }

    /**
     * @param array<string, array{type: string, required: bool, enum?: list<string>, unique?: bool, example?: string|int|float}> $body
     */
    private static function exampleBody(array $body): string
    {
        $example = [];
        foreach ($body as $field => $meta) {
            // A unique string column gets Postman's `{{$randomUUID}}` — substituted fresh
            // on every send — so re-running the whole collection never trips a
            // duplicate-value 422 on create/update/upsert. (Non-string unique columns are
            // rare; they fall through to the normal placeholder.)
            if (($meta['unique'] ?? false) === true && $meta['type'] === 'string') {
                $example[$field] = '{{$randomUUID}}';

                continue;
            }
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
     * The id collection-variable name substituted into this request's `{id}` path
     * segment ({{productsId}}, {{archiveId}}) — null when the path has no `{id}`.
     *
     * @param array<string, mixed> $ep
     */
    private static function idVar(array $ep): ?string
    {
        return in_array('id', $ep['pathParams'], true) ? (self::idVarName($ep) ?? 'id') : null;
    }

    /**
     * The id variable a resource/recycle-bin family shares across its requests,
     * independent of whether this particular endpoint's path carries `{id}` — so a
     * create or a list (neither has `{id}`) can still populate the id the show/
     * update/delete/restore requests read.
     *
     * @param array<string, mixed> $ep
     */
    private static function idVarName(array $ep): ?string
    {
        if ($ep['resource'] !== null) {
            return $ep['resource'] . 'Id';
        }

        return str_contains($ep['path'], '_archive') ? 'archiveId' : null;
    }
}

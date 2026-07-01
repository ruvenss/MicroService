<?php

declare(strict_types=1);

namespace App\Libraries\Docs;

use App\Libraries\ResourceRegistry;

/**
 * Builds an LLM-friendly Markdown API reference from the endpoint catalog,
 * grouped by tag, to a stable template.
 */
final class MarkdownGenerator
{
    public static function generate(?ResourceRegistry $registry = null): string
    {
        $byTag = [];
        foreach (EndpointCatalog::all($registry) as $ep) {
            $byTag[$ep['tag']][] = $ep;
        }

        $out = "# MicroService API reference\n\n"
            . "> Generated from the resource registry by `php spark docs:generate` — do not edit by hand.\n\n"
            . "All `/api/v1/*` endpoints require `Authorization: Bearer <prefix>.<secret>` except **health**. "
            . "Success responses are `{ \"data\": ..., \"meta\": ... }`; errors are RFC 9457 "
            . "`application/problem+json`. Every response carries `X-Request-Id`; rate limits surface via "
            . "`X-RateLimit-*` and `429`. Responses expose no PHP/CodeIgniter/Apache fingerprint.\n\n";

        foreach ($byTag as $tag => $endpoints) {
            $out .= "## {$tag}\n\n";
            foreach ($endpoints as $ep) {
                $out .= self::endpoint($ep);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $ep
     */
    private static function endpoint(array $ep): string
    {
        $md = "### `{$ep['method']} {$ep['path']}`\n\n"
            . "- **Summary:** {$ep['summary']}\n"
            . '- **Auth:** ' . ($ep['auth'] ? 'bearer' : 'none (open)') . "\n"
            . '- **Scope:** ' . ($ep['scope'] ?? '—') . "\n";

        if ($ep['pathParams'] !== []) {
            $md .= '- **Path params:** ' . implode(', ', array_map(static fn (string $p): string => "`{$p}`", $ep['pathParams'])) . "\n";
        }

        if ($ep['query'] !== []) {
            $md .= "- **Query params:**\n";
            foreach ($ep['query'] as $q) {
                $md .= "  - `{$q['name']}` — {$q['description']}\n";
            }
        }

        if (is_array($ep['body'])) {
            $md .= "- **Body (JSON):**\n";
            foreach ($ep['body'] as $field => $meta) {
                $req = $meta['required'] ? 'required' : 'optional';
                $md .= "  - `{$field}` ({$meta['type']}, {$req})\n";
            }
        } elseif (is_string($ep['bodyExample'] ?? null)) {
            $md .= "- **Body (JSON example):**\n\n```json\n{$ep['bodyExample']}\n```\n";
        }

        $md .= "- **Success:** `{$ep['success']}`"
            . ($ep['successKind'] === 'none' ? " (no body)\n" : " (`{$ep['successKind']}` envelope)\n");

        return $md . "\n";
    }
}

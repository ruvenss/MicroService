<?php

declare(strict_types=1);

namespace App\Libraries\Docs;

/**
 * Single source of truth for the generated documentation artefacts (absolute
 * path => exact file contents). Used by `docs:generate` (writes), `docs:check`
 * (drift gate), and DocsInSyncTest — so the three stay byte-for-byte consistent.
 *
 * The static HTML viewer is excluded (it never changes with the API).
 */
final class DocsBundle
{
    private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;

    /**
     * @return array<string, string>
     */
    public static function artifacts(): array
    {
        return [
            FCPATH . 'docs/openapi.json'                                  => (string) json_encode(OpenApiGenerator::generate(), self::JSON_FLAGS),
            ROOTPATH . 'docs/api/README.md'                               => MarkdownGenerator::generate(),
            ROOTPATH . 'docs/postman/MicroService.postman_collection.json' => (string) json_encode(PostmanGenerator::generate(), self::JSON_FLAGS) . "\n",
        ];
    }
}

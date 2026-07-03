<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Docs\DocsBundle;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Regenerates all API documentation from the resource registry so it can never
 * drift: OpenAPI 3.1 + a searchable HTML viewer (humans), Markdown (LLMs), and
 * an importable Postman collection. Run after adding/changing any endpoint.
 */
class DocsGenerate extends BaseCommand
{
    protected $group       = 'Documentation';
    protected $name        = 'docs:generate';
    protected $description = 'Generate OpenAPI, HTML, Markdown, and Postman docs from the registry.';

    public function run(array $params)
    {
        foreach (DocsBundle::artifacts() as $path => $contents) {
            $this->write($path, $contents);
        }
        $this->write(FCPATH . 'docs/index.html', $this->html());

        CLI::write('Documentation generated:', 'green');
        CLI::write('  public/docs/openapi.json   (OpenAPI 3.1)');
        CLI::write('  public/docs/index.html     (searchable HTML viewer)');
        CLI::write('  docs/api/README.md         (Markdown for LLMs)');
        CLI::write('  docs/postman/MicroService.postman_collection.json');
    }

    private function write(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($path, $contents);
    }

    private function html(): string
    {
        return <<<'HTML'
            <!doctype html>
            <html lang="en">
            <head>
              <meta charset="utf-8">
              <meta name="viewport" content="width=device-width, initial-scale=1">
              <title>MicroService API</title>
              <style>body { margin: 0; padding: 0; }</style>
            </head>
            <body>
              <redoc spec-url="openapi.json"></redoc>
              <script src="https://cdn.redoc.ly/redoc/latest/bundles/redoc.standalone.js"></script>
            </body>
            </html>
            HTML;
    }
}

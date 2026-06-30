<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Libraries\QueryParser;
use App\Libraries\ResourceRegistry;
use App\Libraries\ResponseEnvelope;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Self-description of the API surface: lists registered resources and how each
 * may be queried. Describes only the resources already exposed via CRUD (no
 * engine disclosure). Will be gated behind an admin scope once auth lands.
 */
class Discovery extends BaseController
{
    public function resources(): ResponseInterface
    {
        $registry = ResourceRegistry::instance();
        $data     = [];

        foreach ($registry->slugs() as $slug) {
            $definition = $registry->get($slug);
            if ($definition === null) {
                continue;
            }

            $data[] = [
                'resource'   => $slug,
                'endpoint'   => "/api/v1/{$slug}",
                'fields'     => $definition->outputColumns(),
                'writable'   => $definition->fillable,
                'sortable'   => $definition->sortable,
                'filterable' => $definition->filterable,
                'operators'  => QueryParser::OPERATORS,
                'perPage'    => ['default' => $definition->perPageDefault, 'max' => $definition->perPageMax],
            ];
        }

        return $this->response->setJSON(ResponseEnvelope::wrap($data));
    }
}

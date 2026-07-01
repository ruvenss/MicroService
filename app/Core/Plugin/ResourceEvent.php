<?php

declare(strict_types=1);

namespace App\Core\Plugin;

use App\Models\GenericResourceModel;

/**
 * Mutable context passed to resource lifecycle listeners. Plugins subscribe via
 * CodeIgniter Events in their Plugin::boot() and mutate the relevant field:
 *
 *   resource.beforeSave  → $event->data   (create/update payload, before validation)
 *   resource.beforeQuery → $event->model  (list query builder — add constraints)
 *   resource.serialize   → $event->row    (a single outgoing row)
 *
 * Because the event is an object, mutations made by listeners are seen by the
 * generic engine — extending behaviour without touching the core.
 */
final class ResourceEvent
{
    /** @var array<string, mixed> */
    public array $data;

    /** @var array<string, mixed> */
    public array $row;

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $row
     */
    public function __construct(
        public readonly string $resource,
        public readonly string $action,
        array $data = [],
        array $row = [],
        public readonly ?GenericResourceModel $model = null,
    ) {
        $this->data = $data;
        $this->row  = $row;
    }
}

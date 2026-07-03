<?php

declare(strict_types=1);

namespace App\Core\Plugin;

use App\Models\GenericResourceModel;

/**
 * Mutable context passed to resource lifecycle listeners. Plugins subscribe via
 * CodeIgniter Events in their Plugin::boot() and mutate the relevant field:
 *
 *   resource.beforeSave   → $event->data  (create/update payload, before validation)
 *   resource.beforeQuery  → $event->model (list query builder — add constraints)
 *   resource.beforeDelete → $event->row   (row about to be archived — may veto)
 *   resource.serialize    → $event->row   (a single outgoing row)
 *
 * Because the event is an object, mutations made by listeners are seen by the
 * generic engine — extending behaviour without touching the core. A listener on a
 * "before" hook may also cancel() the operation, which the engine turns into a
 * neutral 409 (used today by resource.beforeDelete — e.g. refuse to delete a row
 * still referenced elsewhere).
 */
final class ResourceEvent
{
    /** @var array<string, mixed> */
    public array $data;

    /** @var array<string, mixed> */
    public array $row;

    private bool $cancelled = false;

    private ?string $cancelReason = null;

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

    /** Veto the operation from a "before" listener; the engine returns 409 with this reason. */
    public function cancel(string $reason = 'Operation not permitted.'): void
    {
        $this->cancelled    = true;
        $this->cancelReason = $reason;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    public function cancelReason(): ?string
    {
        return $this->cancelReason;
    }
}

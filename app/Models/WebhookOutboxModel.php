<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class WebhookOutboxModel extends Model
{
    protected $table         = 'webhook_outbox';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'event',
        'resource',
        'record_id',
        'target_url',
        'payload_json',
        'signature',
        'status',
        'attempts',
        'last_error',
        'created_at',
        'delivered_at',
    ];

    /**
     * Rows still eligible for delivery (pending, or failed under the attempt cap).
     *
     * @return list<array<string, mixed>>
     */
    public function deliverable(int $maxAttempts, int $limit): array
    {
        return $this->whereIn('status', ['pending', 'failed'])
            ->where('attempts <', $maxAttempts)
            ->orderBy('id', 'ASC')
            ->findAll($limit);
    }
}

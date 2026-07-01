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
        'claim_token',
        'claimed_at',
        'next_attempt_at',
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

    /**
     * Atomically claim a batch of deliverable rows for exclusive delivery.
     *
     * A single row-locked UPDATE flips up to $limit eligible rows to `dispatching`
     * and stamps them with this dispatcher's $token; concurrent `webhooks:dispatch`
     * runs therefore partition the work and can never POST the same row twice to
     * n8n. Rows stuck in `dispatching` past $staleSeconds (a crashed dispatcher)
     * are reclaimed. Returns the rows this call now owns.
     *
     * @return list<array<string, mixed>>
     */
    public function claim(string $token, int $maxAttempts, int $limit, int $staleSeconds): array
    {
        $limit = max(1, $limit);
        $stale = date('Y-m-d H:i:s', time() - $staleSeconds);

        // A failed row is only re-claimable once its backoff has elapsed
        // (next_attempt_at due); pending rows have next_attempt_at NULL = now. A
        // stale `dispatching` row is reclaimed regardless (its dispatcher crashed).
        $sql = 'UPDATE ' . $this->db->DBPrefix . $this->table . "
                SET status = 'dispatching', claim_token = ?, claimed_at = NOW()
                WHERE attempts < ?
                  AND (
                        (status IN ('pending', 'failed') AND (next_attempt_at IS NULL OR next_attempt_at <= NOW()))
                        OR (status = 'dispatching' AND claimed_at < ?)
                      )
                ORDER BY id ASC
                LIMIT " . $limit;
        $this->db->query($sql, [$token, $maxAttempts, $stale]);

        return $this->where('claim_token', $token)
            ->where('status', 'dispatching')
            ->orderBy('id', 'ASC')
            ->findAll();
    }
}

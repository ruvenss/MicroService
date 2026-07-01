<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Core\Plugin\ResourceEvent;
use App\Models\WebhookOutboxModel;
use Closure;
use Config\Services;
use Config\Webhooks;
use Throwable;

/**
 * Outbound webhooks. On a resource after-event, enqueues one signed outbox row
 * per matching subscription; `dispatch()` (via `spark webhooks:dispatch`) POSTs
 * pending rows to their targets with an HMAC signature, retrying failures.
 *
 * Enqueue happens on the API thread (fast, DB-only); delivery is out-of-band, so
 * a slow/unavailable n8n never affects the API response.
 */
final class WebhookDispatcher
{
    /**
     * Test seam: fn(string $url, array $headers, string $body): int (HTTP status).
     */
    public static ?Closure $sender = null;

    /** Test seam: overrides the resolved Config\Webhooks when set. */
    public static ?Webhooks $configOverride = null;

    private static function config(): Webhooks
    {
        return self::$configOverride ?? config('Webhooks');
    }

    /**
     * Listener for resource.after* events. Enqueues matching subscriptions.
     */
    public static function enqueue(ResourceEvent $event): void
    {
        try {
            $config = self::config();
            if ($config->subscriptions === []) {
                return;
            }

            // Use the resource's declared primary key, not a hardcoded 'id', so
            // the notification carries the record id even for resources keyed on
            // something else (the engine is generic over primaryKey).
            $definition = ResourceRegistry::instance()->get($event->resource);
            $pk         = $definition !== null ? $definition->primaryKey : 'id';
            $recordId   = $event->row[$pk] ?? null;

            $eventKey = $event->resource . '.' . $event->action;
            $payload  = (string) json_encode([
                'event'     => $eventKey,
                'resource'  => $event->resource,
                'action'    => $event->action,
                'id'        => $recordId,
                'data'      => $event->row,
                'previous'  => $event->action === 'afterUpdate' ? $event->data : null,
                'requestId' => RequestContext::id(),
                'timestamp' => Timestamp::now(),
            ]);

            $model = new WebhookOutboxModel();

            foreach ($config->subscriptions as $sub) {
                if (! self::matches($sub['events'], $event->resource, $event->action)) {
                    continue;
                }

                $model->insert([
                    'event'        => $eventKey,
                    'resource'     => $event->resource,
                    'record_id'    => $recordId !== null ? (string) $recordId : null,
                    'target_url'   => $sub['url'],
                    'payload_json' => $payload,
                    'signature'    => $sub['secret'] !== '' ? hash_hmac('sha256', $payload, $sub['secret']) : '',
                    'status'       => 'pending',
                    'attempts'     => 0,
                    'created_at'   => date('Y-m-d H:i:s'),
                ]);
            }
        } catch (Throwable) {
            // Best effort — enqueue must never break the API response.
        }
    }

    /** A row left in `dispatching` longer than this (crashed dispatcher) is reclaimable. */
    private const CLAIM_STALE_SECONDS = 300;

    /** Exponential-backoff base and ceiling between retries (seconds). */
    private const BACKOFF_BASE_SECONDS = 60;
    private const BACKOFF_MAX_SECONDS  = 3600;

    /** Neutral User-Agent on outbound deliveries — never advertises PHP/CodeIgniter. */
    private const USER_AGENT = 'MicroService-Webhook/1.0';

    /**
     * Deliver pending/retriable outbox rows. Rows are first claimed atomically so
     * that overlapping `webhooks:dispatch` runs never deliver the same row twice.
     *
     * @return array{processed: int, sent: int, failed: int}
     */
    public static function dispatch(int $limit = 100): array
    {
        $config = self::config();
        $model  = new WebhookOutboxModel();
        $token  = bin2hex(random_bytes(16));
        $rows   = $model->claim($token, $config->maxAttempts, $limit, self::CLAIM_STALE_SECONDS);

        $sent   = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $attempts = (int) $row['attempts'] + 1;

            $status = self::post((string) $row['target_url'], [
                'Content-Type'      => 'application/json',
                'User-Agent'        => self::USER_AGENT, // neutral — never reveals the engine to n8n
                'X-Event'           => (string) $row['event'],
                'X-Signature'       => (string) $row['signature'],
                // Stable id (same across retries of this row) so n8n can dedupe;
                // the attempt number lets a receiver see redelivery.
                'X-Webhook-Id'      => (string) $row['id'],
                'X-Webhook-Attempt' => (string) $attempts,
            ], (string) $row['payload_json']);

            if ($status >= 200 && $status < 300) {
                $model->update($row['id'], [
                    'status'          => 'delivered',
                    'attempts'        => $attempts,
                    'delivered_at'    => date('Y-m-d H:i:s'),
                    'last_error'      => null,
                    'claim_token'     => null,
                    'claimed_at'      => null,
                    'next_attempt_at' => null,
                ]);
                $sent++;
            } else {
                // Release the claim and schedule the next retry with exponential
                // backoff, so a flapping/unavailable n8n is not hammered and the
                // attempt budget is spread over time (until the attempt cap).
                $model->update($row['id'], [
                    'status'          => 'failed',
                    'attempts'        => $attempts,
                    'last_error'      => 'HTTP ' . $status,
                    'claim_token'     => null,
                    'claimed_at'      => null,
                    'next_attempt_at' => date('Y-m-d H:i:s', time() + self::backoffSeconds($attempts)),
                ]);
                $failed++;
            }
        }

        return ['processed' => count($rows), 'sent' => $sent, 'failed' => $failed];
    }

    /**
     * Delay before the Nth retry: BASE · 2^(attempts-1), capped at MAX. So with a
     * 60 s base: 60 s, 120 s, 240 s, 480 s, … (n8n gets breathing room to recover).
     */
    private static function backoffSeconds(int $attempts): int
    {
        $delay = self::BACKOFF_BASE_SECONDS * (2 ** max(0, $attempts - 1));

        return (int) min($delay, self::BACKOFF_MAX_SECONDS);
    }

    /**
     * Replay the dead-letter queue (rows that exhausted `maxAttempts`) back into
     * the delivery pipeline — e.g. after n8n recovers from a long outage.
     *
     * @return array{deadLettered: int, resurrected: int}
     */
    public static function retryDeadLettered(int $limit = 100): array
    {
        $config = self::config();
        $model  = new WebhookOutboxModel();

        return [
            'deadLettered' => $model->deadLetterCount($config->maxAttempts),
            'resurrected'  => $model->resurrectDeadLettered($config->maxAttempts, $limit > 0 ? $limit : 100),
        ];
    }

    /**
     * @param list<string> $events
     */
    private static function matches(array $events, string $resource, string $action): bool
    {
        $acceptable = ['*', $resource . '.' . $action, $resource . '.*', '*.' . $action];

        foreach ($events as $event) {
            if (in_array($event, $acceptable, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $headers
     */
    private static function post(string $url, array $headers, string $body): int
    {
        if (self::$sender !== null) {
            return (self::$sender)($url, $headers, $body);
        }

        try {
            $response = Services::curlrequest(['timeout' => 5, 'http_errors' => false])
                ->post($url, ['headers' => $headers, 'body' => $body]);

            return $response->getStatusCode();
        } catch (Throwable) {
            return 0;
        }
    }
}

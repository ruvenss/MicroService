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

            $eventKey = $event->resource . '.' . $event->action;
            $payload  = (string) json_encode([
                'event'     => $eventKey,
                'resource'  => $event->resource,
                'action'    => $event->action,
                'id'        => $event->row['id'] ?? null,
                'data'      => $event->row,
                'previous'  => $event->action === 'afterUpdate' ? $event->data : null,
                'requestId' => RequestContext::id(),
                'timestamp' => gmdate('c'),
            ]);

            $model = new WebhookOutboxModel();

            foreach ($config->subscriptions as $sub) {
                if (! self::matches($sub['events'], $event->resource, $event->action)) {
                    continue;
                }

                $model->insert([
                    'event'        => $eventKey,
                    'resource'     => $event->resource,
                    'record_id'    => isset($event->row['id']) ? (string) $event->row['id'] : null,
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

    /**
     * Deliver pending/retriable outbox rows.
     *
     * @return array{processed: int, sent: int, failed: int}
     */
    public static function dispatch(int $limit = 100): array
    {
        $config = self::config();
        $model  = new WebhookOutboxModel();
        $rows   = $model->deliverable($config->maxAttempts, $limit);

        $sent   = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $status = self::post((string) $row['target_url'], [
                'Content-Type' => 'application/json',
                'X-Event'      => (string) $row['event'],
                'X-Signature'  => (string) $row['signature'],
            ], (string) $row['payload_json']);

            if ($status >= 200 && $status < 300) {
                $model->update($row['id'], [
                    'status'       => 'delivered',
                    'attempts'     => (int) $row['attempts'] + 1,
                    'delivered_at' => date('Y-m-d H:i:s'),
                    'last_error'   => null,
                ]);
                $sent++;
            } else {
                $model->update($row['id'], [
                    'status'     => 'failed',
                    'attempts'   => (int) $row['attempts'] + 1,
                    'last_error' => 'HTTP ' . $status,
                ]);
                $failed++;
            }
        }

        return ['processed' => count($rows), 'sent' => $sent, 'failed' => $failed];
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

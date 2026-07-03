<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Outbound webhook subscriptions. On a resource mutation, the matching
 * subscriptions are enqueued to the outbox and delivered (HMAC-signed) by
 * `spark webhooks:dispatch` — the framework's push integration with n8n.
 *
 * Configure the common single-endpoint case via env (WEBHOOK_URL / WEBHOOK_SECRET
 * / WEBHOOK_EVENTS), or add entries to $subscriptions in code for multiple.
 */
class Webhooks extends BaseConfig
{
    /**
     * Each: ['url' => string, 'secret' => string, 'events' => list<string>].
     * An `events` entry matches `{resource}.{action}`, `{resource}.*`, `*.{action}`, or `*`.
     *
     * @var list<array{url: string, secret: string, events: list<string>}>
     */
    public array $subscriptions = [];

    /** Give up after this many delivery attempts. */
    public int $maxAttempts = 5;

    public function __construct()
    {
        parent::__construct();

        $url = env('WEBHOOK_URL');
        if ($url !== null && $url !== '') {
            $this->subscriptions[] = [
                'url'    => (string) $url,
                'secret' => (string) (env('WEBHOOK_SECRET') ?? ''),
                'events' => array_map('trim', explode(',', (string) (env('WEBHOOK_EVENTS') ?? '*'))),
            ];
        }
    }
}

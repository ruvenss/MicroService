#!/bin/sh
# =============================================================================
#  Scheduler sidecar — the piece that makes the outbound n8n push channel work.
#
#  The app container only serves HTTP (Apache + PHP-FPM); it *enqueues* webhooks
#  to the transactional outbox but never delivers them. This worker runs the same
#  image (same env: DB_*, REDIS_*, WEBHOOK_*, RETENTION_*) with the spark CLI and:
#    - delivers queued webhooks to n8n every DISPATCH_INTERVAL seconds
#      (`webhooks:dispatch` — HMAC-signed, retried with backoff), and
#    - prunes the transient tables once a day (`maintenance:prune` — retention).
#
#  Dead-letter replay (`webhooks:retry`) is deliberately NOT automated: it is a
#  manual step after an n8n outage, so a still-down endpoint is never hammered.
# =============================================================================
set -eu

DISPATCH_INTERVAL="${DISPATCH_INTERVAL:-30}"
last_prune_day=""

echo "[scheduler] up — webhooks:dispatch every ${DISPATCH_INTERVAL}s, maintenance:prune daily"

while true; do
    php spark webhooks:dispatch >/dev/null 2>&1 || echo "[scheduler] webhooks:dispatch failed (will retry next tick)"

    # Run the daily prune the first time we see a new UTC calendar day.
    today="$(date -u +%Y-%m-%d)"
    if [ "${today}" != "${last_prune_day}" ]; then
        php spark maintenance:prune >/dev/null 2>&1 || echo "[scheduler] maintenance:prune failed"
        last_prune_day="${today}"
    fi

    sleep "${DISPATCH_INTERVAL}"
done

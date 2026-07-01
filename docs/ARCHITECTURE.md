# MicroService — Architecture

> Status: **design** (no application code yet). This document is the source of truth for the
> intended system. Keep it in sync as code lands. Companion: [PLAN.md](PLAN.md) (phased roadmap).

## 1. Purpose

MicroService is a **reusable CodeIgniter 4 microservice framework** that exposes essential
**REST CRUD** capabilities over a **single database**. It is meant to be the standardized
starting point for new services in the organization — clone it, declare your resources, get a
consistent, secure, tested REST API without rewriting the plumbing each time.

The design goal is **continuity and standardization**: every service built from this looks the
same (routing, auth, error shape, pagination, testing), so any engineer — or Claude — can move
between services with zero relearning.

## 2. Technology stack

| Concern | Choice | Notes |
| --- | --- | --- |
| Framework | **CodeIgniter 4.7.x** (latest 4.7.3) | Chosen for org standardization & continuity. |
| Language | **PHP 8.5** (container runtime), **ZTS** build for `parallel` | PHP 8.5 needs CI ≥ 4.7.0. Code stays 8.4-compatible. |
| Database | **MySQL 8.0+**, InnoDB, `utf8mb4`, **external**, **persistent connections** | Single DB per service; never inside the container (§17). |
| Cache / counters | **Redis** (phpredis), bundled with the deployment | Rate limiting, API-key lookup cache, usage counters. Not data-of-record. |
| Web server | **Apache2** + **PHP-FPM** via `mpm_event` + `mod_proxy_fcgi` | See `apache2-infrastructure-expert`. |
| Packaging | **Docker** image: PHP 8.5 + Apache2 + Redis, OPcache/JIT/preload | Self-contained, DB-external (§17). |
| Dep mgmt | **Composer** | |
| Tests | **PHPUnit** (per-change) + higher-level API/contract suites | See `phpunit-testing`, `automation-tester-specialist`. |

## 3. Locked architectural decisions

These were decided deliberately. Do not change without recording the new decision here.

1. **CRUD style — generic, config-driven engine.** A single generic resource controller +
   model serves *every* entity. New resources are added by declaring them in a config registry
   (table, fields, validation, permissions) — **no per-entity PHP required** for standard CRUD.
   Custom behavior is possible via hooks/overrides, but the default path is zero-code.
2. **Auth — bearer API keys, multiple per service, per-key permissions, usage tracked.**
   `Authorization: Bearer <key>`. A service may have many keys; each key carries a set of
   permission scopes and its usage is recorded. (See §7.)
3. **Response shape — wrapped `{data, meta}` for success, RFC 9457 Problem Details for errors.**
   (See §6.)
4. **Full auditing — every transaction is recorded in the database for later audit.** Access is
   logged for all requests; data mutations are logged with before/after snapshots. (See §13.)
5. **Deletes are archival (recycle bin), not destructive.** Every delete moves the full row into
   an archive table from which it can be restored to the original table. (See §14.)
6. **Sealed core, plugin-based extension.** Developers **never modify `app/` (the core)**. All
   feature work lives in self-contained **plugins** under `plugins/` that register resources,
   endpoints, and hooks through stable extension points. (See §15.)
7. **Documentation is auto-generated on every change.** Whenever a function/endpoint is added or
   changed, API docs are regenerated in two forms: a **searchable static HTML** site for humans
   and **structured Markdown** for LLMs. (See §16.)
8. **Self-contained, DB-external container.** The service ships as a Docker image bundling
   **PHP 8.5 + Apache2 + Redis + all required extensions**, tuned for performance and parallel
   execution. **No database runs in the container** — MySQL is external and reached over the
   network. **Database connections are persistent.** (See §17.)

## 4. Request lifecycle (big picture)

```text
Client
  │  HTTP  Authorization: Bearer <key>
  ▼
Apache2 ──fcgi──► PHP-FPM ──► CodeIgniter front controller (public/index.php)
  │
  ▼  Filters (before), in order:
  1. RequestId          → attach/propagate X-Request-Id
  2. ContentGuard       → enforce JSON, parse/validate body
  3. ApiKeyAuth         → resolve & verify bearer key → attach AuthContext (401 if invalid)
  4. RateLimit          → per-key window check via Redis (429 if exceeded)
  5. RequirePermission  → resource+action scope check against key (403 if missing)
  │
  ▼
Router → Generic ResourceController (resource slug from route)
  │        └─ loads ResourceDefinition from Config\Resources
  │        └─ GenericResourceModel configured from the definition
  │        └─ QueryParser turns ?filter/?sort/?page/?fields into a safe query
  │        └─ Validation (create/update rule sets) on writes
  ▼
MySQL (single connection)  [optional Redis read-through for hot lookups]
  │
  ▼  Filters (after):
  - UsageTracker        → record request (key, resource, action, status, latency) → Redis/DB
  - SecurityHeaders     → HSTS, CSP, X-Content-Type-Options, etc.
  - ResponseEnvelope    → ensure {data, meta} | problem+json
  ▼
Client
```

## 5. The generic resource engine (heart of the framework)

### 5.1 Resource definition

Resources are declared in a registry (`app/Config/Resources.php`). Each entry is a
**ResourceDefinition** describing one table's API surface:

```php
// Illustrative shape — not final code.
'products' => [
    'table'        => 'products',
    'primaryKey'   => 'id',
    'softDelete'   => true,            // use deleted_at instead of hard delete
    'timestamps'   => true,            // created_at / updated_at managed automatically
    'fields' => [
        'id'         => ['type' => 'int',    'readonly' => true],
        'sku'        => ['type' => 'string', 'fillable' => true],
        'name'       => ['type' => 'string', 'fillable' => true],
        'price'      => ['type' => 'decimal','fillable' => true],
        'status'     => ['type' => 'enum',   'fillable' => true, 'values' => ['active','archived']],
        'secret_note'=> ['type' => 'string', 'fillable' => true, 'hidden' => true], // never serialized
    ],
    'rules' => [
        'create' => ['sku' => 'required|is_unique[products.sku]', 'name' => 'required|max_length[200]', 'price' => 'required|decimal'],
        'update' => ['name' => 'permit_empty|max_length[200]', 'price' => 'permit_empty|decimal'],
    ],
    'query' => [
        'filterable' => ['status', 'sku', 'price'],   // whitelist — nothing else is filterable
        'sortable'   => ['name', 'price', 'created_at'],
        'defaultSort'=> '-created_at',
        'perPage'    => ['default' => 25, 'max' => 100],
    ],
    'permissions' => [                  // scope required per action; defaults to {resource}:{action}
        'read'   => 'products:read',
        'create' => 'products:write',
        'update' => 'products:write',
        'delete' => 'products:delete',
    ],
],
```

**Security principle:** filtering, sorting, and field exposure are **allow-lists**. A column
not listed under `filterable`/`sortable` cannot be targeted by a client; `hidden` fields are
never serialized. This closes off mass-assignment, injection via column names, and data leaks
by construction.

### 5.2 Generic model & controller

- **`GenericResourceModel`** extends CodeIgniter's `Model`, configured at runtime from a
  `ResourceDefinition` (table, PK, allowed fields, validation rules, soft delete, timestamps).
- **`ResourceController`** (one class, generic) implements the CRUD actions below. It resolves
  the resource from the route slug, builds the model, runs the query/validation, and returns
  the standard envelope. Per-resource customization is via optional override classes that
  extend the generic controller/model and hook specific methods.

### 5.3 Endpoints (uniform across every resource)

Base path: **`/api/v1`**. `{resource}` is the registry slug.

| Method | Path | Action | Success |
| --- | --- | --- | --- |
| GET | `/{resource}` | list (filter/sort/paginate/sparse) | 200 `{data:[...], meta:{pagination}}` |
| GET | `/{resource}/{id}` | show | 200 `{data:{...}}` |
| POST | `/{resource}` | create | 201 + `Location` header, `{data:{...}}` |
| PUT | `/{resource}/{id}` | full replace | 200 `{data:{...}}` |
| PATCH | `/{resource}/{id}` | partial update | 200 `{data:{...}}` |
| DELETE | `/{resource}/{id}` | **archival delete** — move row to recycle bin (§14) | 204 (no body) |

**Service meta endpoints:**

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/api/v1/health` | liveness/readiness (DB + Redis ping) |
| GET | `/api/v1/_resources` | discovery: per resource — endpoint, output `fields`, writable `schema` (type + required per field, plus `enum` choices for constrained fields — same values OpenAPI/Postman advertise), `sortable`/`filterable` + `operators`, `upsertKey`, and `perPage`, so an n8n workflow can auto-build valid create/update/upsert requests from one live call (auth required) |
| GET | `/api/v1/openapi.json` | generated OpenAPI 3.1 spec derived from the registry |
| GET | `/api/v1/_archive` | list archived (deleted) records — recycle bin (§14) |
| GET | `/api/v1/_archive/{archiveId}` | view one archived record |
| POST | `/api/v1/_archive/{archiveId}/restore` | restore an archived record to its original table |
| GET | `/api/v1/_audit` | query the data-mutation audit trail (§13) |

Every `GET` route also answers **`HEAD`** (registered via `match(['get','head'], …)`, so the same
auth/rate-limit filters apply): same status and headers as `GET` (`ETag`, `X-RateLimit-*`, …) with an
empty body, for cheap liveness/existence probes from n8n and uptime monitors. An anonymous `HEAD` on a
gated resource still returns `401` — it never leaks existence. Verified in the container
(`HEAD /health` → `200`, 0-byte body; `HEAD /products` → `401` without a key, `200` with `ETag` when
authorized). Covered by `HeadRequestTest`.

### 5.4 Query conventions (list endpoint)

- **Pagination:** two modes, same endpoint.
  - *Offset (default):* `?page=2&perPage=50` (perPage capped per resource). Meta returns
    `page, perPage, total, totalPages`.
  - *Keyset/cursor (opt-in):* add a `cursor` param (empty to start), then follow
    `meta.pagination.nextCursor` until it is null. Iterates by the primary key with
    `WHERE pk > cursor` — no `OFFSET`/`COUNT`, so paging stays index-fast and never skips or
    duplicates rows when the table changes mid-iteration. This is the shape n8n's cursor
    pagination consumes. Direction follows an explicit `sort={pk}` / `-{pk}`; any other `sort`
    alongside `cursor` is rejected (keyset needs a unique ordered key). Cursors are opaque,
    versioned tokens (not a security boundary). Meta returns `perPage, cursor, hasMore, nextCursor`.
- **Sorting:** `?sort=-created_at,name` — one or more comma-separated columns applied in order (`-` =
  descending). Only `sortable` columns are honoured (each is allow-listed, never interpolated). A column
  that is not sortable is **rejected with `400`** — validated in `QueryParser` alongside `filter`/`fields`
  and aggregated into the same problem response — rather than silently dropped, so a caller (e.g. an n8n
  workflow that relies on the order) never receives default-ordered rows while believing its sort applied.
  The **primary key is always a valid sort target and is always appended as a final tiebreaker**, so rows
  equal on a non-unique sort column (e.g. `price`, `created_at`) get a deterministic total order — offset
  pagination never skips or duplicates a row at a page boundary. If no `sort` is given the resource's
  `defaultSort` applies. Covered by `QueryFilterTest`, `QueryParserTest`.
- **Filtering:** `?filter[status]=active&filter[price][gte]=100`. Operators:
  `eq` (also the bare `filter[col]=v` shorthand), `ne`, `gt`, `gte`, `lt`, `lte`, `like` (contains),
  `in` / `nin` (comma-separated set membership / exclusion). Combine two on one column for a range
  (`filter[price][gte]=10&filter[price][lte]=50`). Only `filterable` columns allowed; values are bound
  as parameters (never interpolated). For `like`, a caller's own `%` and `_` are **escaped** so they
  match literally (via `escapeLikeString`, with the matching `ESCAPE` clause) — `filter[col][like]=%`
  therefore finds a literal percent, not every row: no accidental match-everything, and no LIKE-wildcard
  lever to force full-table scans on the external DB from an exposed endpoint. **Numeric columns** (cast
  `int`/`float`) require **numeric values** on comparison/equality/set operators: a non-numeric value is
  rejected with `400` rather than let through to MySQL, which would silently coerce `price > 'abc'` to
  `price > 0` and return the whole table — the same "fail loudly, never silently return the wrong rows"
  rule as `sort`/`fields`. Every operator is covered by `QueryFilterTest`, `QueryParserTest`.
- **Sparse fields:** `?fields=id,name,price` — restrict returned columns (hidden fields always excluded).
- **Conditional reads (caching):** every `GET` (show and list) returns a strong `ETag` — a content
  hash of the response body (no stored state, no engine fingerprint). Resend it as `If-None-Match`
  and, when nothing changed, the server replies `304 Not Modified` with an empty body. `If-None-Match: *`
  always matches. This lets an n8n schedule poll cheaply — only changed data crosses the wire.
- **Conditional writes (optimistic concurrency):** an item `PATCH`/`PUT`/`DELETE` may carry `If-Match`
  with an ETag fetched earlier; if the record changed since, the write is refused with `412 Precondition
  Failed` (RFC 9110) and nothing is applied — so two n8n workflows editing the same record can't
  silently clobber each other (lost update). No `If-Match` = unconditional (last-write-wins);
  `If-Match: *` requires only that the record still exist. Covered by `OptimisticConcurrencyTest`.

## 6. Response & error contract

### 6.1 Success envelope

```json
{
  "data": { "id": 1, "sku": "ABC", "name": "Widget", "price": "9.99" },
  "meta": { "pagination": { "page": 1, "perPage": 25, "total": 42, "totalPages": 2 } }
}
```
- `data` is an object for single-resource responses, an array for collections.
- `meta` is present when there is pagination or other metadata; omitted otherwise.

### 6.2 Error envelope — RFC 9457 Problem Details (`application/problem+json`)

```json
{
  "type":   "https://errors.example.com/validation",
  "title":  "Validation Failed",
  "status": 422,
  "detail": "One or more fields are invalid.",
  "instance": "/api/v1/products",
  "requestId": "01J...",
  "errors": { "sku": ["The sku field is required."] }
}
```
Canonical status usage: 400 malformed, 401 missing/invalid key, 403 key lacks scope, 404 unknown
resource/id, 405 method, 409 conflict, 422 validation, 429 rate limited, 500 unexpected.
All errors flow through one handler so the shape is guaranteed.

## 7. Authentication, authorization & usage tracking

> **Status (implemented):** bearer API keys with per-key scopes are live. `ApiKeyAuth` +
> `RequirePermission` filters gate `/api/v1/*` (health open; `_resources` needs a valid key); keys are
> managed via `php spark key:create|key:list|key:revoke|key:rotate`. **Zero-downtime rotation:**
> `key:rotate <prefix> [--grace-hours N]` installs a fresh secret and keeps the previous one valid until
> `previous_expires_at` (default 24 h) — during the window `ApiKeyModel::verifySecret` accepts either
> (constant-time), so an n8n credential can be updated before the old secret stops working. **Brute-force / DoS guard:** repeated auth
> failures from one IP are counted (cache) and, past 30/minute, answered with **429** instead of 401 —
> so an exposed service can't be key-probed or flooded on the auth path. A valid key never fails, so
> legitimate n8n traffic is never throttled by this (it hits only the generous per-key rate limit).

### 7.1 Key format & verification

- Presented as `Authorization: Bearer <key>` where `<key> = <prefix>.<secret>`.
- `<prefix>` is a public, indexed identifier (fast row lookup); `<secret>` is high-entropy random.
- Storage: only a **hash of the secret** is stored. Because secrets are high-entropy, a fast
  keyed digest (SHA-256 / HMAC-SHA256) is appropriate — bcrypt/argon are unnecessary here — and
  comparison uses `hash_equals` (constant time). (Confirm with `php-security-engineer`.)
- A short-lived Redis cache of `prefix → {key row, scopes}` avoids a DB hit per request;
  invalidated on revoke.

### 7.2 Permissions (scopes)

- Scope grammar: **`{resource}:{action}`** where action ∈ `{read, write, delete}`
  (`write` covers create+update; `delete` separate). Wildcards allowed: `products:*`, `*:read`, `*`.
- Each request resolves the required scope from the resource definition (§5.1 `permissions`) and
  the `RequirePermission` filter checks the authenticated key holds it → 403 otherwise.

### 7.3 Data model (auth)

```text
api_keys
  id              BIGINT PK
  prefix          VARCHAR  UNIQUE         -- public lookup id
  secret_hash     CHAR(64)                -- SHA-256 of secret
  name            VARCHAR                 -- human label
  status          ENUM('active','revoked')
  rate_limit      INT NULL                -- requests/min override
  expires_at      DATETIME NULL
  last_used_at    DATETIME NULL
  created_at, updated_at

api_key_scopes
  id              BIGINT PK
  api_key_id      BIGINT FK → api_keys(id)
  scope           VARCHAR                 -- e.g. 'products:write', '*:read'
  UNIQUE(api_key_id, scope)

api_request_log            -- append-only usage trail
  id              BIGINT PK
  api_key_id      BIGINT FK
  request_id      CHAR(26)
  method          VARCHAR(8)
  path            VARCHAR
  resource        VARCHAR NULL
  action          VARCHAR NULL
  status          SMALLINT
  latency_ms      INT
  ip              VARBINARY(16)
  created_at      DATETIME(3)
  INDEX(api_key_id, created_at)

-- High-frequency counters live in Redis (INCR per key per window) and are periodically
-- flushed to a rollup table api_key_usage_daily(api_key_id, day, count) to keep the hot
-- path off MySQL. The append-only api_request_log is for audit/forensics.
```

### 7.4 Key management

A CLI command (CodeIgniter Spark, e.g. `php spark key:create`) mints a key: it generates the
secret, prints it **once**, stores only the hash, and assigns scopes. Revocation flips `status`
and busts the Redis cache. No key secret is ever logged or retrievable after creation.

## 8. Rate limiting

> **Status (implemented):** per-key **fixed-window** limiter (`RateLimit` filter) backed by the CI4
> cache service — **file** cache in dev/test, **Redis** in production (`cache.handler=redis`; a Redis
> sidecar is in `docker-compose.dev.yml`). Global default 120/min, overridable per key
> (`api_keys.rate_limit`, e.g. `key:create --rate-limit N`). On exceed → `429` + `Retry-After`. The
> full trio `X-RateLimit-Limit` / `X-RateLimit-Remaining` / `X-RateLimit-Reset` (epoch second the window
> frees up) rides on **every** response, so an n8n workflow can self-throttle proactively instead of only
> reacting to a `429`. Each request is also logged to `api_request_log` by the `UsageTracker`
> after-filter, stamping `last_used_at`.

Target end state: a **sliding-window / token-bucket** limiter as an **atomic Redis Lua script**
(see `php-redis-specialist`) plus an `api_key_usage_daily` rollup. Fail open or closed per config
(default: fail closed for writes, open for reads if Redis is down — decision to confirm).

## 9. Cross-cutting concerns

- **Single DB connection**, configured via env (`.env`); no secrets in VCS.
- **Filters** (CI4 filter pipeline) implement every cross-cutting step in §4 — keeps controllers
  thin and the generic engine focused on CRUD.
- **Validation** uses CI4's validation with per-resource create/update rule sets from the registry.
- **Observability:** every request carries an `X-Request-Id` (generated if absent), echoed in
  responses and logs and stored on the usage log for tracing.
- **Versioning:** URI-based `/api/v1`; additive changes only within a version.

## 10. Directory layout (target)

```text
app/
  Config/
    Resources.php          # resource registry — the generic engine's input
    Auth.php               # scope grammar, key/cache settings
    Routes.php             # maps /api/v1/{resource}[/{id}] → generic controller
  Controllers/Api/
    ResourceController.php  # generic CRUD (one class for all resources)
    HealthController.php
    DiscoveryController.php # _resources, openapi.json
  Models/
    GenericResourceModel.php
    ApiKeyModel.php
  Filters/
    RequestId.php  ContentGuard.php  ApiKeyAuth.php
    RateLimit.php  RequirePermission.php  UsageTracker.php  SecurityHeaders.php
  Libraries/
    ResourceDefinition.php  QueryParser.php
    ResponseEnvelope.php    ProblemDetails.php  Authorization.php
  Database/Migrations/      # api_keys, api_key_scopes, api_request_log, sample resource
  Database/Seeds/
  Commands/                 # key:create, key:revoke, docs:generate, plugin:* etc.
  Core/
    Plugin/                 # PluginManager, PluginInterface, manifest loader (§15)
    Docs/                   # DocGenerator (HTML + Markdown) (§16)

plugins/                    # ⬅ DEVELOPERS WORK HERE ONLY — the core (app/) is sealed
  <Vendor>/<PluginName>/
    plugin.json             # manifest: name, version, namespace, provides, requires
    Plugin.php              # bootstrap: register() resources/routes/hooks, boot()
    Resources/              # resource definitions this plugin contributes to the registry
    Controllers/            # optional custom (non-CRUD) endpoints, extend generic base
    Models/  Filters/  Listeners/
    Database/Migrations/    # plugin-owned schema
    docs/                   # plugin-authored notes (merged into generated docs)
    tests/

public/
  index.php                 # front controller; Apache DocumentRoot points here
  docs/                     # ⬅ GENERATED searchable HTML API docs (do not edit by hand)
tests/Unit  tests/Integration  tests/Api
docs/
  ARCHITECTURE.md  PLAN.md   # design (hand-written)
  api/                       # ⬅ GENERATED Markdown API docs for LLMs (do not edit by hand)

docker/                     # image build + tuned configs (§17)
  Dockerfile                # multi-stage PHP 8.5 ZTS + Apache + FPM
  apache/  php/  redis/      # vhost, php.ini/fpm (opcache/JIT/preload/pConnect), redis.conf
docker-compose.yml          # app + redis sidecar — NO mysql (DB is external)
docker-compose.dev.yml      # OPTIONAL dev-only throwaway MySQL for local tests
Makefile                    # `make build` / `make up` — the auto-docker entrypoint
```

## 11. Specialist ownership map

| Area | Agent / skill |
| --- | --- |
| API contract, status codes, OpenAPI | `rest-api-specialist` |
| PHP 8.4/8.5 implementation | `php-84-85-expert` |
| Schema, indexes, query tuning | `mysql-expert` |
| Redis (cache, rate limit, counters) | `php-redis-specialist` |
| Auth, key hashing, hardening | `php-security-engineer` |
| Apache + FPM, deployment | `apache2-infrastructure-expert` |
| Profiling, OPcache/JIT, N+1 | `php-optimization-engineer` |
| Per-change unit tests | `phpunit-testing` |
| API/contract/integration/CI | `automation-tester-specialist` |
| Sequencing & design changes | `software-architect-planner` |

## 12. Open questions (to resolve as we build)

1. Rate-limit fail-open vs fail-closed when Redis is unavailable (current lean: closed for writes).
2. ~~Soft-delete default~~ — **resolved**: deletes are archival framework-wide (§14).
3. Relationship/embedding support (e.g. `?include=`) — out of scope for v1, revisit later.
4. **Bulk create/update/delete implemented** — collection-level batch mutations, each all-or-nothing
   in one transaction, capped at 100 items, so an n8n workflow can mutate many rows in one call:
   - `POST /api/v1/{resource}` with a JSON array of objects → bulk create (per-index 422, one audit
     row each, typed `{data, meta:{created}}`).
   - `PATCH /api/v1/{resource}` with a JSON array of objects, each carrying its primary key plus the
     fields to change → bulk update (`{data, meta:{updated}}`; before/after audit per row).
   - `DELETE /api/v1/{resource}` with `{"ids": [...]}` (or a bare id array) → bulk archival delete
     (each row moved to the recycle bin, restorable; `{meta:{deleted}}`).
   - `PUT /api/v1/{resource}` → **upsert** (create-or-update) by the resource's declared natural key
     (`upsertKey`, e.g. `sku` for products). Send one object or an array; each item matched on the key
     is updated (update rules), the rest created (create rules), all-or-nothing —
     `{data, meta:{upserted, created, updated}}`. This lets an **n8n data-sync** workflow reconcile
     records in one idempotent call instead of GET-then-POST/PATCH (which races). Fires the right
     `afterCreate`/`afterUpdate` events + webhooks per item. Resources without an `upsertKey` return 422.
   Any invalid/unknown/duplicate-key item aborts the whole batch with per-index errors and writes nothing.
   Scopes are the same as the single-row routes (`:write` for create/update/upsert, `:delete` for delete);
   both accept an `Idempotency-Key` so n8n retries replay the first response.
5. **Retention/purge — implemented for the transient tables.** `php spark maintenance:prune`
   (`--dry-run` to preview; run on a schedule) purges **expired `idempotency_keys`**, **`api_request_log`**
   older than `RETENTION_ACCESS_LOG_DAYS` (default 30), and **delivered `webhook_outbox`** rows older
   than `RETENTION_DELIVERED_WEBHOOK_DAYS` (default 7) — see `Config\Retention` and
   `App\Libraries\Maintenance\Pruner`, covered by `MaintenancePruneTest`. `audit_log` (compliance trail)
   and `archived_records` (restorable recycle bin) are **deliberately never auto-pruned**; their
   long-term retention/rollup remains an ops policy decision.
6. Whether audit/archive payloads need encryption-at-rest or field redaction for sensitive resources.

## 13. Auditing (transaction audit trail)

> **Status (implemented):** both layers are live. Access logging → `api_request_log` (UsageTracker,
> §8). Data mutations → `audit_log` via `AuditWriter`, written **inside the same transaction** as the
> change (create/update/delete/restore) with before/after snapshots + changed-field list; hidden
> fields are redacted. Read the trail at `GET /api/v1/_audit` (scope `audit:read`), filterable by
> `?resource=` / `?record_id=`. **Change-data-capture polling:** `?sinceId=N` returns only entries
> after audit id `N`, **oldest-first**, so an n8n schedule can pull changes in order and resume from the
> last id it saw — a pull-based complement to the push webhooks (§18). Covered by `AuditTrailTest`.
> Deletes are archival (§14). Outstanding: routing CLI key actions through `audit_log`.

**Requirement:** every transaction in the microservice is recorded in the database for later
auditing. Two complementary layers, both write to the single DB:

1. **Access audit — `api_request_log` (§7.3).** One row per request: who (api key), what
   (method, path, resource, action), outcome (status, latency), and origin (ip, request id).
   This answers "who called what, when, and what happened."
2. **Data audit — `audit_log`.** One row per *state-changing* operation (create / update /
   delete / restore, plus key management actions) capturing **before** and **after** snapshots
   so any change can be reconstructed and attributed. When the change feed is *read* back
   (`GET /_audit`, incl. `?sinceId=` polling), those snapshots are presented through the
   resource's own casts (`ResourceDefinition::castRow` — the same one the CRUD response uses):
   the `after` an n8n workflow consumes carries typed `int`/`float`/`bool` and ISO-8601-`Z`
   timestamps, **identical to a live `GET`**, so both surfaces parse with one rule. The stored
   JSON is untouched (forensic record); only the presentation is normalised.

```sql
audit_log
  id            BIGINT PK
  request_id    CHAR(26)                 -- correlates with api_request_log
  api_key_id    BIGINT FK NULL           -- actor (NULL for system/CLI)
  action        ENUM('create','update','delete','restore','key.create','key.revoke', ...)
  resource      VARCHAR NULL             -- registry slug, when applicable
  record_id     VARCHAR NULL             -- affected row PK
  before_json   JSON NULL                -- full prior state (NULL on create)
  after_json    JSON NULL                -- full new state (NULL on delete)
  changed       JSON NULL                -- list of changed fields (updates)
  ip            VARBINARY(16)
  created_at    DATETIME(3)
  INDEX(resource, record_id), INDEX(api_key_id, created_at), INDEX(request_id)
```

**Implementation:** writes go through a single `AuditWriter` invoked by the generic model on
every mutation, **inside the same DB transaction** as the change itself — so a change and its
audit record commit or roll back together (no orphaned or missing audit rows). Sensitive
`hidden` fields are redacted in snapshots. The access-audit row is written by the
`UsageTracker` after-filter (§4). Audit tables are **append-only** at the application layer
(no update/delete paths exposed); retention/purge is an ops policy (open question §12.5).

## 14. Archival deletes (recycle bin & restore)

**Requirement:** delete never destroys data — it dumps the full row into an archive table from
which it can be restored to the original table.

### 14.1 Mechanism

On `DELETE /{resource}/{id}`, within **one DB transaction**:
1. Read the full source row.
2. Insert a record into `archived_records` containing the complete row as JSON plus restore
   metadata (origin table, PK, who/when, request id).
3. Remove the row from the source table.
4. Write an `audit_log` entry (`action = delete`, `before_json` = the row).

This is a generic mechanism driven by the resource registry — it works for any resource with
no per-entity code. (A per-resource flag may force a pure soft-delete `deleted_at` instead where
moving the row is undesirable; archival is the default.)

```sql
archived_records
  id            BIGINT PK
  resource      VARCHAR                  -- registry slug
  source_table  VARCHAR                  -- physical table the row came from
  record_id     VARCHAR                  -- original PK value
  payload_json  JSON                     -- complete original row
  deleted_by    BIGINT FK NULL           -- api_key_id
  request_id    CHAR(26)
  deleted_at    DATETIME(3)
  restored_at   DATETIME(3) NULL         -- set when restored; NULL = still archived
  INDEX(resource, record_id), INDEX(deleted_at)
```

### 14.2 Browse & restore

`GET /api/v1/_archive` lists archived rows (scope `archive:read`), filterable by `?resource=` and by
restoration state: **`?restored=false`** = still-deleted (restorable), **`?restored=true`** = already
restored, omitted = all — so an n8n recycle-bin workflow can list exactly what it can restore.
`GET /api/v1/_archive/{archiveId}` returns the full record; its **`payload`** is presented through the
resource's own casts (`ResourceDefinition::castRow`), so an n8n workflow inspecting the recycle bin
before restoring parses it in the **same typed, ISO-8601-`Z` shape as a live `GET`**. Restore re-inserts
the *raw* `payload_json` (never the cast form — a `Z` timestamp would not fit a DATETIME column), so the
presentation change is read-only. (Covered by `ArchivalDeleteTest`.)

`POST /api/v1/_archive/{archiveId}/restore` re-inserts `payload_json` into `source_table`,
within a transaction:
- Conflict handling: if the original PK is now taken, fail `409` (the operator can resolve);
  configurable to restore under a new PK later.
- Marks `restored_at`, and writes an `audit_log` entry (`action = restore`, `after_json` = row).
- Restore requires an elevated scope (e.g. `{resource}:restore` or an admin scope) — it is not
  granted by ordinary write access.

### 14.3 Why a generic archive table (vs per-table shadow tables)

A single JSON-payload archive keeps the framework zero-config per resource and lets the recycle
bin span every entity uniformly. Trade-off: archived data isn't queryable by column without JSON
extraction. Acceptable because the archive's job is restore + audit, not analytics. (`mysql-expert`
to confirm indexing/retention; very high delete-volume resources may warrant a dedicated strategy.)

## 15. Plugin architecture (sealed core)

**Requirement:** developers must never touch the core. All feature development happens in
`plugins/`, consumed by the core as extensions.

> **Status (implemented — resource contribution + events):** `App\Core\Plugin\PluginManager` discovers
> `plugins/<Vendor>/<Name>/plugin.json` + a `Plugin` class (`PluginInterface`), and `ResourceRegistry`
> merges plugin-contributed resources with the (now empty) core `Config\Resources`. The sample
> **`products`** resource lives in `plugins/Sample/Catalog` — full CRUD, filtering, auth, audit,
> archival delete, and generated docs with **zero** core change.
>
> The generic engine also fires **lifecycle events** plugins subscribe to (CodeIgniter Events, via a
> mutable `App\Core\Plugin\ResourceEvent`). **Before/around:** `resource.beforeSave` (mutate the
> create/update payload before validation), `resource.beforeQuery` (constrain the list query — tenant
> scoping), `resource.beforeDelete` (inspect the row about to be archived and optionally **`cancel()`**
> it — the engine returns a neutral `409`, e.g. refuse to delete a row still referenced elsewhere; a
> veto aborts a bulk delete entirely, all-or-nothing), `resource.serialize` (transform each outgoing
> row). **Post-commit:** `resource.afterCreate`,
> `resource.afterUpdate` (carries prior state in `->data`), `resource.afterDelete`, and
> `resource.afterRestore` — fired **after** the transaction commits, so a plugin can safely react to a
> durable change: invalidate a cache, cascade, or notify. (The built-in **n8n webhook** enqueue is *not*
> driven from these events — it is a transactional outbox written inside the mutation's own transaction;
> see §18.) Covered by `ResourceEventsTest` and `AfterEventsTest`.
>
> **Plugins are self-contained:** `Config\Autoload` registers each enabled plugin's namespace, so a
> plugin owns its **migrations** (`plugins/<V>/<N>/Database/Migrations/`, run by `spark migrate --all`),
> models, and views — not just the resource definition. **`php spark make:plugin Vendor/Name`**
> scaffolds a working plugin (manifest, `Plugin` class registering a starter resource, an owned
> migration, README); verified end-to-end (scaffold → migrate → `POST /api/v1/<slug>` 201, zero core
> edits). **Lifecycle management (implemented):** `php spark plugin:list` shows every plugin
> (enabled **and** disabled) with a status column; `php spark plugin:enable|disable <Vendor/Name>`
> flips the manifest's `enabled` flag (case-insensitive match, idempotent pretty-printed rewrite) so an
> operator never hand-edits JSON. Disabling drops the plugin's resources from the registry and from
> regenerated docs on the next boot; data and migrations are left intact. Covered by `PluginToggleTest`.
>
> **Sealed-core guard (implemented):** `php spark guard:seal` (run in CI next to stan/cs/docs, and
> covered by `SealGuardTest`) enforces the invariant that keeps the core generic — **no file in `app/`
> may reference a concrete plugin** (a `Plugins\<Vendor>` import or hard-coded namespace). The plugin
> mechanism (the `Plugins\{$vendor}` template, `plugins/` glob discovery) is allowed; only a hard
> dependency on a specific plugin fails. This is the runnable half of the seal — the complementary
> "a feature PR must not touch `app/`" is a repo policy (protected path / core-change label).
> **Still to come:** delete/restore *before* event hooks.

**Delivery model — monorepo.** The core (`app/`) and all plugins (`plugins/`) live in a single
repository. "Sealed core" is therefore enforced by **convention + CI guard** (a PR touching `app/`
without a core-change label fails), not by a package boundary. If the framework later needs to be
shared across independent repos, the core can be extracted to a Composer package without changing
the plugin contract.

### 15.1 The contract

- **Core = `app/`** (the generic engine, filters, auth, audit, archive, doc generator). It is
  **read-only to feature developers** and versioned/owned by the framework maintainers. CI can
  enforce this (e.g. a protected-path check that fails a PR touching `app/` without a core label).
- **Plugins = `plugins/<Vendor>/<PluginName>/`** — self-contained units that *extend* the core
  through stable, published extension points. A plugin never edits core files; it **registers**
  into the core.

### 15.2 Plugin anatomy

```text
plugins/Acme/Catalog/
  plugin.json        # manifest (below)
  Plugin.php         # implements PluginInterface: register(PluginManager), boot()
  Resources/         # ResourceDefinition entries → contributed to the central registry
  Controllers/       # optional custom endpoints (extend the generic base controller)
  Models/ Filters/ Listeners/ Commands/
  Database/Migrations/  Database/Seeds/
  docs/              # author notes, merged into generated docs
  tests/
```

`plugin.json` (manifest):

```json
{
  "name": "Acme/Catalog",
  "version": "1.2.0",
  "namespace": "Plugins\\Acme\\Catalog",
  "provides": { "resources": ["products", "categories"], "routes": ["/catalog/import"] },
  "requires": { "core": "^1.0", "plugins": [] },
  "enabled": true
}
```

### 15.3 Lifecycle & discovery

1. **Discover** — at bootstrap, `PluginManager` scans `plugins/`, reads each `plugin.json`,
   resolves `requires`, and orders by dependency. (PSR-4 autoload maps `Plugins\\…` to the path.)
2. **Register** — each `Plugin::register()` contributes resource definitions to the central
   registry, mounts custom routes, binds event listeners, and registers services/commands.
3. **Boot** — `Plugin::boot()` runs after all registrations for any cross-plugin wiring.
4. **Migrate** — plugin migrations run namespaced so each plugin owns its tables.

A plugin can be enabled/disabled via the manifest or `php spark plugin:enable|disable` without
touching core. Disabled plugins contribute nothing.

### 15.4 Extension points (how plugins add behavior without editing core)

- **Resources:** declare `ResourceDefinition`s → full CRUD via the generic engine, zero controller code.
- **Lifecycle hooks (events):** the engine fires events the plugin subscribes to —
  `resource.beforeValidate`, `resource.beforeCreate`/`afterCreate`, `…Update`, `…Delete`,
  `…Restore`, `resource.beforeQuery` (mutate the list query), `resource.serialize` (transform output).
- **Custom endpoints:** for non-CRUD needs, register routes pointing at a plugin controller that
  extends the generic base (still gets auth, audit, envelope, docs for free).
- **Custom validators, filters, CLI commands, services** — all registered, never patched in.

Because plugins ride the generic engine, **everything a plugin adds automatically inherits**
auth/permissions (§7), rate limiting (§8), auditing (§13), archival deletes (§14), the response
envelope (§6), and documentation generation (§16) — without the developer wiring any of it.

## 16. Documentation generation (HTML for humans, Markdown for LLMs)

**Requirement:** every time a developer adds/changes a function or endpoint, API documentation is
auto-generated — searchable HTML for developers, and Markdown for other LLMs to assimilate.

### 16.1 Sources of truth (introspected, not hand-written)

The `DocGenerator` builds docs by introspecting:

- the **resource registry** (every resource: fields, types, validation, scopes, the 6 CRUD endpoints);
- **plugin manifests** + their custom routes/controllers;
- **PHP docblocks / attributes** on plugin controllers and functions (summary, description,
  params, examples, `@since`, owning plugin);
- the generated **OpenAPI 3.1** spec (the machine-readable backbone both outputs derive from).

### 16.2 Outputs

- **`public/docs/` — searchable static HTML.** A self-contained site with client-side full-text
  search (prebuilt index) so developers can browse and search every endpoint, schema, scope, and
  example. Practical approach: render the OpenAPI spec via an embeddable API console (e.g. Redoc /
  Stoplight Elements) plus generated pages per plugin. No server dependency — static files Apache serves.
- **`docs/api/` — structured Markdown for LLMs.** One `.md` per resource/plugin plus an index,
  written to a **stable template** (see §16.3) so an LLM can parse capabilities deterministically.
  The `openapi.json` is emitted alongside as the canonical machine source.

### 16.3 Markdown template (per endpoint/function)

Each generated `.md` entry follows a fixed shape so it's diff-friendly and LLM-parseable:

```text
## {METHOD} {path}            (or: ## {Plugin}::{function})
- **Summary:** one line
- **Owner:** plugin name & version
- **Auth scope:** {resource}:{action}
- **Since:** version
- **Path params / Query params / Body:** typed table(s)
- **Responses:** success envelope example + error (problem+json) examples
- **Notes:** behavior, side effects, audited? archival?
```

### 16.4 Trigger & workflow

- **Command:** `php spark docs:generate` regenerates both outputs from the live registry + code.
- **Policy (enforced in three places):**
  1. **Authoring time** — when Claude adds or changes a function/endpoint, it regenerates docs in
     the same change (this is a standing instruction in `CLAUDE.md` and the `api-doc-generator` skill).
  2. **CI gate** — the pipeline runs `docs:generate` and fails if committed docs are stale
     (generated output differs from what's checked in), so docs can never drift from code
     (`DocsInSyncTest`).
  3. **Route↔doc coverage** — `RouteDocCoverageTest` reconciles the declared route surface
     (`Config/Routes.php`) with the `EndpointCatalog` in both directions: every real route must be
     documented (so nothing is missing from the imported Postman collection) and every documented
     endpoint must have a matching route (no phantom that would 404). This caught, e.g., the item
     `PUT` route being routable but undocumented.
- Generated directories (`public/docs/`, `docs/api/`, `openapi.json`) are **build artifacts** —
  never hand-edited; edit the code/docblocks/manifest and regenerate.

## 17. Containerization & runtime performance

**Requirement:** the service auto-dockerizes into one self-contained, high-performance image
running **PHP 8.5 + Apache2 + Redis** with all extensions; **no database in the container**
(MySQL is external); **database connections are persistent**; code paths support parallel execution.

> **Status (built & verified end-to-end):** `docker/Dockerfile` (base **`php:8.5-fpm`** + Apache with
> mod_security/proxy_fcgi/rewrite/headers, mysqli/pdo_mysql/intl) builds; `docker-compose.yml` runs the
> app + a Redis sidecar against the **external** MySQL (`make build && make up`). **Runtime is Apache
> `mpm_event` fronting a PHP-FPM worker pool over `mod_proxy_fcgi`** (decision #1 — *not* mod_php), so
> the request layer is threaded/event-driven and PHP is a sized pool (`docker/php/www.conf`;
> `entrypoint.sh` starts FPM then Apache). Verified in the real container: `apache2ctl -V` reports
> **`Server MPM: event`**, both `apache2` + `php-fpm` processes run, `health` **200** against the
> external DB (persistent `pConnect` connections), full CRUD, **OPcache + JIT** (tracing, 64 M), and
> **OPcache preload** warming the framework + core classes at FPM startup (**376 scripts** — verified
> via `opcache_get_status()`), engine fully hidden (§18.2).
>
> **Config is 12-factor via UNDERSCORE env vars** (`DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASSWORD/
> DB_PERSISTENT`, `APP_BASE_URL`, `CI_ENVIRONMENT`, `WEBHOOK_URL/WEBHOOK_SECRET/WEBHOOK_EVENTS`): under
> FPM these reach PHP through the pool's **`clear_env = no`** (the FastCGI equivalent of the old mod_php
> vhost `PassEnv`), and `Config\App`/`Config\Database`/`Config\Webhooks` map them (`env()`; port → int).
>
> **Full feature set verified in the real container** (Apache mpm_event + PHP-FPM + external MySQL):
> auth (401/403), CRUD, typed casts, bulk create, filtering, idempotency replay, audit trail, archival
> delete + restore, `_me`, and outbound **webhook enqueue** — all working, with the engine fully hidden
> (`Server: MicroService`; `/index.php`, `/phpinfo.php`, `TRACE`, `/.env` all neutral problem+json,
> zero Apache/PHP markers). **Deferred:** the `redis` extension (file cache until then).

### 17.1 Image composition

- **Base:** `php:8.5` line. Build **ZTS (Zend Thread Safe)** so the `parallel` extension is
  available for in-request parallelism (§17.4). Multi-stage build: a build stage runs
  `composer install --no-dev --optimize-autoloader` and compiles extensions; the runtime stage
  copies only what's needed. Runs as a **non-root** user; minimal packages.
- **Web tier:** **Apache2** with `mpm_event` fronting **PHP-FPM** over `mod_proxy_fcgi`
  (not `mod_php`). DocumentRoot → `public/`. (`apache2-infrastructure-expert`.)
- **Extensions:** `opcache`, `pdo_mysql`/`mysqli`, `redis` (phpredis), `intl`, `parallel`
  (ZTS), plus the app's needs. JIT-capable build.
- **Redis:** bundled with the deployment. Default topology is a **Redis sidecar service** in the
  compose/pod (clean separation, restartable); an all-in-one image variant is available where a
  single self-contained container is required. Redis holds cache/rate-limit/usage counters only —
  **never** business data of record (that's the external MySQL).
- **Database:** **none in the container.** Connection target is injected via env
  (`database.default.hostname`, etc.) pointing at the external MySQL.

### 17.2 Persistent database connections (important)

- CodeIgniter DB config uses **`'pConnect' => true`** (persistent PDO/mysqli). Connections are
  held open per FPM worker and reused across requests, eliminating per-request connect/TLS/auth cost.
- **Capacity rule (must enforce):** persistent connections are pinned per worker, so
  **Σ(FPM `pm.max_children` across all running containers/replicas) ≤ MySQL `max_connections`** (minus
  headroom for admin/other clients). Size the FPM pool from this backward, not from CPU alone.
  Document the chosen numbers. (`mysql-expert` + `apache2-infrastructure-expert` jointly own this.)
- **Hygiene:** rely on transactions that always commit/rollback (no half-open state leaking to the
  next request on a reused connection); set sane MySQL `wait_timeout`/`interactive_timeout` and app
  retry-on-stale-connection. For very high replica counts, an external pooler (ProxySQL) is the
  escape hatch — noted, not in v1.

### 17.3 Performance tuning (ultra-performant target)

- **OPcache** enabled and sized; `opcache.validate_timestamps=0` in prod (code is baked into the
  image). **Preloading is implemented and verified:** `opcache.preload=/var/www/html/preload.php`
  (as `www-data`) warms the framework `system/` **and** the core's own hot dirs (`app/Controllers`,
  `Libraries`, `Models`, `Filters`, `Core`) — 373 scripts compiled and linked into shared memory at
  startup, so workers skip per-request compile/link. Non-class files (`Config`/`Views`/`Language`/
  `Common.php`) are excluded to keep preload clean.
- **JIT** enabled and measured — keep it only where it demonstrably helps (CPU-bound work);
  most request time here is I/O, so the wins come first from persistent connections, Redis
  caching, and query/index tuning. **Measure, don't guess** (`php-optimization-engineer`).
- Tuned `realpath_cache`, FPM `pm` mode sized to memory + the connection rule above, sensible keepalive.
- **Response compression (implemented, `docker/apache/compression.conf`):** `mod_deflate` gzips
  `application/json` / `application/problem+json` / `text/plain`, so n8n pulling list responses transfers
  ~85% less (verified: a `products` list went **6463 → 911 bytes**). Crucially it sets **`DeflateAlterETag
  NoChange`** — Apache would otherwise append `-gzip` to the ETag, and since the app computes its own
  strong ETag and handles `If-None-Match`/`304` itself, that would silently break conditional GET.
  Verified: with gzip on, an `If-None-Match` replay still returns **304**, and the ETag carries no
  `-gzip` suffix. (HTTP/2 / brotli would be added at the TLS-terminating proxy, not this container.)
- **FPM pool resilience (`docker/php/www.conf`):** `request_terminate_timeout = 30s` kills a hung
  request (e.g. a stalled external DB) and recycles the worker, so a slow dependency can't pin the pool
  and exhaust it under load (a self-inflicted DoS if the service is exposed); `pm.max_requests = 1000`
  recycles workers to bound any leak; `security.limit_extensions = .php` restricts what the pool will
  execute. Verified in the rebuilt container (`php-fpm -tt`).

### 17.4 Parallel execution

- **Across requests:** `mpm_event` + the FPM worker pool already give process-level concurrency.
- **Within a request (fan-out):** for parallel work like multi-source reads or batch operations,
  use the **`parallel` extension** (requires the ZTS build, §17.1) or **PHP Fibers + async I/O**
  (e.g. amphp) for concurrent I/O. Use this deliberately for genuine fan-out; default request
  handling stays simple and synchronous.

### 17.5 Compose / deploy shape

```text
docker/
  Dockerfile                 # multi-stage: build (composer, ext) → runtime (PHP8.5 ZTS + Apache + FPM)
  apache/ vhost.conf         # mpm_event + mod_proxy_fcgi → public/
  php/ php.ini, fpm.conf     # opcache+JIT, preload, pm sizing, pConnect-aware
  redis/ redis.conf          # maxmemory-policy for cache role
docker-compose.yml           # services: app (Apache+FPM), redis (sidecar). NO mysql service.
docker-compose.dev.yml       # OPTIONAL local-only override that adds a throwaway MySQL for tests
Makefile / spark command     # `make build` / `make up` — the "auto-docker" entrypoint
```

- Production compose/pod has **no MySQL service**; the DB host comes from env/secrets.
- A clearly-marked **dev-only** override may spin a disposable MySQL purely for local integration
  tests — never used in production.
- Healthchecks hit `GET /api/v1/health`, which distinguishes **readiness** (default — pings the
  external MySQL **and** the cache/Redis backend; `200` when all up, `503 degraded` with per-check
  status otherwise) from **liveness** (`?probe=live` — dependency-free, always `200`, so an
  orchestrator never restarts the container over a transient DB/cache blip). The image ships a Docker
  `HEALTHCHECK` (a dependency-free PHP probe — no curl/wget added) that marks the container
  healthy/unhealthy on readiness; verified reaching `healthy`. The endpoint is open (no key) and
  engine-neutral. **The readiness result is cached ~5 s** (`health_readiness`), so a burst of monitor
  probes — or a flood against the open endpoint — runs at most one real `SELECT 1` + cache round-trip
  per window instead of one per request (a guard against health-flood DoS on the external DB if the
  service is exposed); short enough that a genuine outage still surfaces within the healthcheck retry
  budget. Covered by `ApiHealthTest`.
- `.env`/secrets injected at runtime (DB host/user/pass, Redis, signing material) — never baked
  into the image.

## 18. Stealth / anti-fingerprinting

**Requirement:** if the service is ever exposed, a probe must not be able to tell what runs behind
it (PHP, CodeIgniter, Apache version). Defence is layered — application + web server + runtime.

### 18.1 Application layer — the `Stealth` filter

`app/Filters/Stealth.php` runs on **every** response (registered in `Filters::$required['after']`,
last, so it sees the final headers — even on 404s and errors). It:

- removes `X-Powered-By` (both from the framework header bag and via `header_remove()`, since the
  PHP SAPI injects it independently of the framework);
- strips the CodeIgniter **DebugToolbar** correlation headers (`Debugbar-Time`/`Debugbar-Link`) and
  the toolbar is removed from the filter chain entirely (useless for a JSON API, and an instant tell);
- sets an engine-neutral `Server: MicroService`;
- adds baseline security headers: `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`,
  a restrictive `Content-Security-Policy` (`default-src 'none'`), and `Permissions-Policy`.
- every response carries `Cache-Control: no-store` (max-age=0, no-cache), so a shared cache/CDN never
  stores a per-key/sensitive response if the service is exposed behind one — n8n's manual
  `ETag`/`If-None-Match` revalidation is unaffected. Asserted across every response path by
  `StealthAuditTest`. The importable Postman collection's full create→show→update→upsert→delete flow is
  verified to work with the generated example bodies.

Other fingerprints removed: the session cookie is renamed `ci_session` → `sid`, and the default
**welcome page is deleted** — unknown paths and the bare root return a neutral `application/problem+json`
404 via `Routes::set404Override()`, disclosing nothing about the router or app.

### 18.2 Web-server layer — Apache (`public/.htaccess` + vhost)

- **Hide PHP in URLs:** any request referencing a `.php` file (`/index.php`, `/phpinfo.php`,
  `/index.php/<route>` PATH_INFO) returns the **same neutral problem+json 404 as any unknown path**,
  via the application-layer `HidePhp` filter (inspects the raw `REQUEST_URI`). We **do not** block `.php`
  with a server-context `RewriteRule ^ - [R=404]`: mod_rewrite's `R=404` *bypasses* `ErrorDocument` and
  emits Apache's recognisable default 404 HTML — an engine leak (found and removed in the stealth
  audit). Routing `.php` through the front controller keeps the response neutral. Clean, extensionless
  URLs only.
- `mod_headers` re-asserts the security headers and unsets `X-Powered-By` for static files too.
- Dotfiles are denied; directory listing is off; `ServerSignature Off`.
- **Server-token masking (implemented in the container):** the `Server` header is replaced entirely
  with `MicroService` via mod_security `SecServerSignature`. Two non-obvious requirements, both set in
  `docker/apache/stealth.conf`: `SecRuleEngine On` (with `DetectionOnly` it does not rewrite the
  header) **and** `ServerTokens Full` (mod_security overwrites the signature in place, so the full
  string must exist — with `Prod` it is pre-truncated to `Apache` and cannot be replaced). No rule
  sets are loaded, so nothing is inspected/blocked. Verified: real container emits `Server: MicroService`.
- **Neutral server-level errors:** the vhost maps `ErrorDocument 400/403/404/405/406/413/500/501/502/503`
  to a static `public/error.json` (`application/problem+json`), so **any** failure Apache handles
  *itself* — a denied dotfile (`403`), a disabled method like `TRACE` (`405`), an oversized body
  (`413`), a malformed request line (`400`), the backend being down (`5xx`) — never returns Apache's
  branded default HTML. The engine stays hidden even when the app isn't reached. App-level 4xx/5xx
  already return problem+json from the front controller. **Audited** by probing the live container:
  `/index.php`, `/phpinfo.php`, `/.env`, `TRACE`, `OPTIONS`, unknown paths — all return neutral
  problem+json with `Server: MicroService` and zero Apache/PHP/CodeIgniter markers in the body.

> **Payload-size limits (DoS guard, "in case exposed"):** the vhost sets `LimitRequestBody 8388608`
> (8 MiB) to refuse abusive bodies at the edge before PHP buffers them (answered by the neutral
> `ErrorDocument 413`). Behind it, the `ContentGuard` filter enforces a tighter **1 MiB** contract limit
> on write bodies (checking `Content-Length` first, then actual length) and returns a clean
> `413 Content Too Large` problem+json — comfortably fitting a 100-item bulk batch. Covered by
> `tests/Api/ContentGuardTest.php`.

### 18.3 Runtime layer — PHP / container

- `expose_php = Off` in the container `php.ini` (so the SAPI never emits `X-Powered-By` in the first
  place); production `CI_ENVIRONMENT=production` disables verbose CI error pages.
- Error responses are JSON/problem+json — no stack traces or framework branding leak in production.

> **Tested:** `tests/Api/StealthHeadersTest.php` and `NotFoundTest.php` assert no PHP/CodeIgniter/
> Debugbar fingerprint and the neutral 404 body (application layer). `StealthAuditTest.php` extends this
> to a full-surface regression guard: it asserts the neutral `Server` token, absent `X-Powered-By`/
> Debugbar/CI-version headers, the baseline security headers, **and** a body free of engine/internals
> markers (CodeIgniter, Xdebug, stack traces, `/system/`, `/var/www`, `vendor/`, …) across **every**
> response path — `200`, `401`, `404` (unknown path *and* unknown resource), `400`, `415`, `413`, `429`
> — plus the uncaught-exception handler (`ApiExceptionHandler::prepare`), which must never add
> class/file/line/trace to the problem+json.
>
> **Verified in the container (PHP 8.5 + Apache):** with the real image (`docker/`, see §17.6) running
> against the external MySQL, a probe gets: every direct `.php` request (`/index.php`, `/phpinfo.php`,
> `/anything.php`) → **404**; `Server: MicroService` (Apache version masked via mod_security
> `SecServerSignature`; `ServerTokens Prod`); **no** `X-Powered-By`, Apache, PHP, or CodeIgniter
> anywhere; neutral root and enforced auth. A full header fingerprint scan comes back clean.
>
> **PATH_INFO closed:** because Apache's server-context rewrite does not reliably intercept
> `/index.php/<route>` (PATH_INFO on an existing file), PHP is also hidden at the **application layer**:
> the `HidePhp` filter (first in `Filters::$required['before']`) inspects the raw `REQUEST_URI` and
> returns a neutral 404 for **any** path that references a `.php` file — including `/index.php/...`.
> Internal rewrites of clean URLs don't change `REQUEST_URI`, so `/api/v1/health` passes untouched.
> **Verified in the container:** `/index.php/api/v1/health`, `/index.php`, `/phpinfo.php`,
> `/index.php/api/v1/_resources` all → 404; clean URLs unaffected. (`HidePhpTest` covers the detection.)

## 19. n8n integration

The service is consumed by **n8n** workflows (HTTP Request nodes), which shapes several choices:

- **Predictable JSON envelope** (`{data, meta}` / problem+json) so n8n can map fields without
  guesswork; stable error `status`/`title` for branch logic.
- **Bearer API-key auth** maps directly to an n8n *Generic Credential → Header/Bearer*; per-key
  scopes (§7) let each workflow get a least-privilege key, and usage tracking (§13) attributes calls
  back to the workflow.
- **Typed JSON output:** a resource declares `casts` (`int`, `float`, `bool`, `string`, `datetime`) so
  responses carry real JSON types instead of MySQLi's all-strings — n8n maps typed fields without
  conversion nodes. The **`datetime`** cast emits **ISO-8601 UTC** (`2026-07-01T10:19:30Z`) rather than a
  bare `Y-m-d H:i:s`, so n8n's date handling never has to guess the zone (the sample `products` casts
  `created_at`/`updated_at`). The **meta endpoints** (`_audit`, `_archive`, `_me`) format their
  timestamps through the same `App\Libraries\Timestamp` helper, and **server-generated** envelope
  timestamps — the `health` `time` and the webhook payload `timestamp` — go through its `Timestamp::now()`
  companion (both emit the identical `…Z` shape, never a `+00:00` offset). So **every** timestamp on the
  wire — CRUD data, the `_audit?sinceId` change feed, the recycle bin, key metadata, health, and webhook
  envelopes alike — is one uniform ISO-8601 UTC format a consumer parses with a single rule. Covered by
  `OutputCastsTest`, `TimestampTest`, `AuditTrailTest`, `ApiHealthTest`, `WebhookTest`.
- **`GET /api/v1/_me`** lets a workflow introspect its own key (name, scopes, rate limit, expiry — never
  the secret) to verify connectivity and permissions before running.
- **Unauthenticated `/api/v1/health`** for n8n schedule/health checks and uptime polling.
- **Importable Postman collection** (`docs/postman/`) documents each endpoint for humans and serves
  as the reference when configuring the matching n8n node. Example request bodies use **valid,
  validation-passing values** — enum fields (e.g. `status` = `in_list[active,archived]`) are filled with
  a real allowed value, not a `"string"` placeholder — so a human can import the collection and hit
  **Send** on *Create* and get a `201`, not a `422`. OpenAPI carries the matching `enum`/`example`.
  Covered by `DocsGeneratorTest`.
- **Outbound webhooks (implemented):** on a resource mutation the framework enqueues a signed event to
  a **transactional outbox** (`webhook_outbox`) for each matching subscription (`Config\Webhooks`
  / `WEBHOOK_URL`), and `php spark webhooks:dispatch` (run on a schedule) POSTs them to the n8n webhook
  node. **Delivery headers (n8n contract):** `X-Signature` (HMAC-SHA256 of the raw body with the
  subscription secret — n8n recomputes it to verify authenticity), `X-Event` (`{resource}.{action}`),
  **`X-Webhook-Id`** (the outbox row id — *stable across retries*, so n8n can dedupe a redelivered
  event), `X-Webhook-Attempt` (delivery attempt number), and a neutral `User-Agent`
  (`MicroService-Webhook/1.0`) that never reveals the engine to the receiver. **The outbox row is written inside the
  same DB transaction as the mutation** (by the controller, not a post-commit event), so the change and
  its notification commit atomically — a crash after commit can never drop the event (no dual-write gap).
  Enqueue is DB-only on the request thread; delivery is out-of-band with retries (`maxAttempts`), so a
  slow/unavailable n8n never affects the API response. Subscriptions filter by
  `{resource}.{afterCreate|afterUpdate|afterDelete|afterRestore}` / wildcards. This is the service→n8n
  push channel (n8n workflows triggered by data changes). The payload's `id` (and the outbox
  `record_id`) is the resource's **declared `primaryKey` value**, resolved from the registry — not a
  hardcoded `id` — so notifications carry the real key even for resources keyed on something else (the
  engine is generic over `primaryKey`; the Postman collection's create→reuse-id script is likewise keyed
  off the resource's primary key). The payload's **`data`** (and, on update, **`previous`**) is typed
  through the resource's casts at the single enqueue choke point, so **every** event — create, update
  *and delete* — reaches n8n in the same int/float/bool + ISO-8601-`Z` shape as a live `GET` (delete
  `data` and update `previous` were previously raw MySQLi strings). Covered by `WebhookTest`.
  **No double-delivery under concurrency:** `dispatch()` first *claims* a batch with a single row-locked
  `UPDATE … SET status='dispatching', claim_token=? … ORDER BY id LIMIT n`, so overlapping
  `webhooks:dispatch` runs partition the work and never POST the same row twice. The claim is released
  (back to `failed`, retriable) on failure and cleared on delivery; a row stuck in `dispatching` past
  300 s (a crashed dispatcher) is reclaimed. **Exponential backoff:** a failed delivery sets
  `next_attempt_at = now + 60·2^(attempts-1) s` (capped at 1 h), and the claim query skips a `failed`
  row until it is due — so a flapping/unavailable n8n is not hammered and the `maxAttempts` budget is
  spread over time instead of burned in seconds. **Dead-letter replay:** once a row exhausts
  `maxAttempts` it is parked (the claim query ignores it), so no failing endpoint blocks the queue;
  after n8n recovers, `php spark webhooks:retry` (`--dry-run` to just count) resets the dead-lettered
  rows to `pending` with a fresh budget for another `webhooks:dispatch` pass — no event is silently
  lost. Covered by `WebhookTest` (concurrent-claim, stale-reclaim, backoff, dead-letter replay).
- **Idempotency keys (implemented):** a client sends `Idempotency-Key: <key>` on a write; the first
  response is recorded and any retry with the same key + request **replays** it (with
  `Idempotency-Replayed: true`) instead of re-executing — so an n8n retry after a timeout never
  double-creates or double-deletes. Reusing a key with a different request → 422. **Scoped per API
  key** — the lookup filters on `api_key_id` and a `UNIQUE(api_key_id, idem_key)` index enforces it, so
  two different keys reusing the same `Idempotency-Key` never see each other's response (no cross-tenant
  replay; covered by `IdempotencyTest`). 24 h TTL — an expired key re-executes rather than replaying.
  Sequential-retry safe (n8n's behaviour); concurrent-retry de-dup (unique reserve + Redis lock) is a
  follow-up.


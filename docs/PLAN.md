# MicroService — Implementation Plan

> Phased, dependency-ordered roadmap. Companion to [ARCHITECTURE.md](ARCHITECTURE.md) (the design).
> Each phase is independently shippable and ends green (tests pass). Check items off as they land.
> Policy: **every new function ships with a PHPUnit test in the same change** (`phpunit-testing`).

## Guiding rules

- Build the **generic engine once**; never hand-code per-entity CRUD.
- Every cross-cutting concern is a **CodeIgniter filter**, not controller code.
- Every mutation is **audited inside the same DB transaction** as the change (§13 of ARCHITECTURE).
- Deletes are **archival** (§14) — no destructive DELETE on business tables.
- **Core (`app/`) is sealed.** Feature work happens in `plugins/` only (§15). Don't add features to core.
- **Regenerate docs in the same change** as any new/changed function or endpoint (§16) — HTML + Markdown.
- Confirm security-sensitive choices with `php-security-engineer`; schema with `mysql-expert`.

---

## Phase 0 — Bootstrap & tooling

**Goal:** a running, empty CI4 app with DB connectivity, tests, and local serving.

- [ ] `composer create-project codeigniter4/appstarter .` (pin **CI 4.7.x**); commit baseline.
- [ ] Set PHP target **8.4** (CI for 8.4 + 8.5 matrix); `composer.json` `require php ^8.2`.
- [ ] `.env` from `env`: `app.baseURL`, single MySQL connection (`database.default.*`), Redis.
- [ ] Confirm `public/index.php` front controller; lock `BaseURL`, `forceGlobalSecureRequests`.
- [ ] PHPUnit wired (`phpunit.xml`, `tests/` namespaces, `composer test` script).
- [ ] Static analysis: PHPStan (or Psalm) at a high level + coding standard (PER/PSR-12).
- [ ] Local serving: `php spark serve` for dev; document Apache+FPM as the deploy target.
- [ ] CI skeleton (lint + static analysis + `phpunit`) on push/PR (`automation-tester-specialist`).

**Done when:** `composer test` runs green and `GET /` responds through the framework.

---

## Phase 0.5 — Container baseline (dev parity from day one)

**Goal:** the app runs in its real target container immediately, so every later phase is built and
tested in the production runtime (§17). Full perf tuning is finished in Phase 6.

- [ ] `docker/Dockerfile` — multi-stage, **PHP 8.5 ZTS** + Apache (`mpm_event`) + PHP-FPM
      (`mod_proxy_fcgi`), non-root; extensions `opcache, pdo_mysql, redis, intl, parallel`.
- [ ] `docker-compose.yml` — services: **app** + **redis sidecar**; **no MySQL service** (external
      DB via env). `docker-compose.dev.yml` adds a throwaway MySQL for local tests only.
- [ ] DB config `'pConnect' => true` (persistent connections); DB host/creds from env/secrets.
- [ ] `Makefile` (`make build` / `make up`) as the "auto-docker" entrypoint; healthcheck → `/api/v1/health`.
- [ ] Baseline `php.ini`/FPM: OPcache on, `pm` sized provisionally (final sizing in Phase 6).
- [ ] Verify the app serves through Apache→FPM in-container and reaches an external MySQL.

**Done when:** `make up` brings up the service (app + Redis) against an external DB, healthcheck green.

---

## Phase 1 — Response & error foundation

**Goal:** the uniform success/error contract exists before any resource does.

- [x] `ResponseEnvelope` library → `{data, meta}` shaping (single + collection).
- [x] `ProblemDetails` library → RFC 9457 `application/problem+json` builder.
- [x] Global exception/error handler routes **all** failures through ProblemDetails
      (`ApiExceptionHandler` wired in `Config\Exceptions`; verified end-to-end — even a 500 returns
      neutral problem+json with no stack trace/engine disclosure).
- [x] `RequestId` filter (before/after) — adopt a well-formed inbound `X-Request-Id` or mint one;
      echo on the response and embed in problem+json (`RequestContext`).
- [x] `SecurityHeaders` filter (after) — delivered as the `Stealth` filter (CSP, `X-Content-Type-Options`,
      `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`) + anti-fingerprinting (§18). HSTS lands with TLS in P6.
- [ ] `ContentGuard` filter — enforce/parse JSON, reject bad media types (415/400).
- [x] `HealthController` → `GET /api/v1/health` (DB ping now; Redis ping when Redis lands). Neutral
      problem+json 404 override + engine hiding also shipped in this slice.
- [x] Tests: envelope shapes, problem details, request-id behaviour, health endpoint (20 tests green).

**Done when:** health returns the standard envelope and forced errors return problem+json.

---

## Phase 2 — Generic resource engine (core CRUD)

**Goal:** declare a resource in config → full CRUD with filtering/sorting/pagination, no per-entity PHP.

- [ ] `ResourceDefinition` library — typed wrapper over a registry entry (fields, rules, query rules, perms).
- [ ] `app/Config/Resources.php` registry + one **sample resource** (e.g. `products`) end-to-end.
- [ ] `GenericResourceModel` — CI `Model` configured at runtime from a `ResourceDefinition`.
- [ ] `QueryParser` — parse `?filter[...]`, `?sort`, `?page/perPage`, `?fields` against the
      **allow-lists**; bind all values as parameters. Reject unknown columns/operators (400).
- [ ] `ResourceController` (generic) — `index/show/create/update/patch/delete` using the model +
      envelope; validation via per-resource create/update rule sets; 201+`Location` on create.
- [ ] `Routes.php` — `/api/v1/{resource}` and `/api/v1/{resource}/{id}` → generic controller.
- [ ] `DiscoveryController` → `GET /api/v1/_resources` (schema introspection from the registry).
- [ ] Migration + seeder for the sample resource table.
- [ ] Tests: full CRUD happy paths, validation failures (422), 404, filtering/sorting/pagination,
      sparse fields, hidden-field exclusion, allow-list rejection.

**Done when:** adding a registry entry yields a working, tested REST resource with zero new PHP.
> ⚠ Delete here is a placeholder; real delete behavior is **Phase 5 (archival)** — wire DELETE to
> return 501/stub or land Phase 5 before exposing it.

---

## Phase 2.5 — Plugin system & sealed core

**Goal:** developers extend the service via `plugins/` without ever editing `app/` (§15).

- [ ] `PluginInterface` (`register()`, `boot()`) + `plugin.json` manifest schema + loader/validator.
- [ ] `PluginManager` — discover `plugins/`, resolve `requires`, order by dependency, register, boot.
- [ ] PSR-4 autoload mapping for `Plugins\\<Vendor>\\<Name>\\`; namespaced plugin migrations.
- [ ] Wire the central resource registry to **aggregate** definitions contributed by plugins
      (core `Resources.php` no longer hand-edited per entity).
- [ ] Lifecycle **events** fired by the generic engine (`resource.beforeCreate`/`afterCreate`,
      `…Update`, `…Delete`, `…Restore`, `resource.beforeQuery`, `resource.serialize`).
- [ ] Spark commands: `plugin:list`, `plugin:enable`, `plugin:disable`, `make:plugin` (scaffold).
- [ ] Move the Phase 2 sample resource into a **sample plugin** to prove the path end-to-end.
- [ ] CI guard: PR fails if it modifies `app/` without an explicit core-change label.
- [ ] Tests: discovery/ordering, enable/disable, a plugin resource served through the generic engine,
      a plugin event hook firing and mutating behavior.

**Done when:** a new resource/endpoint can be delivered entirely from a plugin, core untouched.

---

## Phase 2.6 — Documentation generator (HTML + Markdown)

**Goal:** docs auto-generate from code so they can never drift; enforced from here on (§16).

- [ ] `DocGenerator` introspects: resource registry, plugin manifests, controller docblocks/attributes.
- [ ] Emit canonical **OpenAPI 3.1** (`public/docs/openapi.json`) as the backbone.
- [ ] **HTML** output → `public/docs/`: searchable static site (Redoc/Stoplight Elements + per-plugin pages).
- [ ] **Markdown** output → `docs/api/`: one file per resource/plugin to the fixed template (§16.3) + index.
- [ ] Spark command `docs:generate` (full) and `docs:check` (fails if generated output is stale).
- [ ] CI gate: run `docs:check`; stale docs fail the build.
- [ ] Standing workflow rule recorded in `CLAUDE.md` + `api-doc-generator` skill: regenerate on every
      function/endpoint change.
- [ ] Tests: generator produces expected HTML+MD for the sample plugin; `docs:check` detects drift.

**Done when:** adding a function/endpoint and running `docs:generate` yields updated, searchable HTML
and LLM-ready Markdown, and CI rejects stale docs.

---

## Phase 3 — Auth: bearer API keys + permissions

**Goal:** multiple keys per service, per-key scopes, verified on every request.

- [ ] Migrations: `api_keys`, `api_key_scopes` (§7.3).
- [ ] `ApiKeyModel` + `Authorization` library (scope grammar `{resource}:{action}`, wildcards).
- [ ] Key hashing & verification — prefix lookup + `hash_equals` on SHA-256 secret (`php-security-engineer`).
- [ ] `ApiKeyAuth` filter (before) — resolve bearer key → `AuthContext`; 401 on missing/invalid/expired/revoked.
- [ ] `RequirePermission` filter (before) — required scope from the resource definition → 403 if absent.
- [ ] Redis read-through cache of `prefix → key+scopes`; invalidate on revoke (`php-redis-specialist`).
- [ ] Spark commands: `key:create` (prints secret once, stores hash, assigns scopes), `key:revoke`, `key:list`.
- [ ] Apply auth + permission filters to all `/api/v1/*` resource routes (health stays open).
- [ ] Tests: 401 paths, 403 scope failures, wildcard scopes, revoked/expired keys, cache invalidation.

**Done when:** resource endpoints require a valid scoped key; key lifecycle is manageable via CLI.

---

## Phase 4 — Usage tracking & rate limiting

**Goal:** every request recorded; per-key limits enforced.

- [ ] Migration: `api_request_log` (§7.3) + optional `api_key_usage_daily` rollup.
- [ ] `UsageTracker` filter (after) — write access-audit row (key, resource, action, status, latency, ip, request id).
- [ ] Redis per-key counters (`INCR`+`EXPIRE`); periodic flush to `api_key_usage_daily`; update `last_used_at`.
- [ ] `RateLimit` filter (before) — atomic Redis Lua sliding-window/token-bucket; global default +
      per-key override; `429` + `Retry-After` (`php-redis-specialist`).
- [ ] Decide Redis-down behavior (fail closed for writes / open for reads — confirm).
- [ ] Tests: usage rows written, counters increment, limit triggers 429, override respected.

**Done when:** usage is queryable in the DB and limits are enforced per key.

---

## Phase 5 — Auditing & archival deletes (recycle bin)

**Goal:** full data-mutation audit trail + non-destructive delete with restore.

- [ ] Migration: `audit_log` (§13), `archived_records` (§14).
- [ ] `AuditWriter` — invoked by `GenericResourceModel` on create/update/delete/restore, writing
      before/after snapshots **inside the same transaction** as the change; redact hidden fields.
- [ ] Rework DELETE → archival: copy full row → `archived_records`, remove from source, audit — all atomic.
- [ ] Archive surface: `GET /api/v1/_archive`, `GET /api/v1/_archive/{id}`,
      `POST /api/v1/_archive/{id}/restore` (elevated scope; 409 on PK conflict).
- [ ] Audit surface: `GET /api/v1/_audit` (filter by resource/record/key/date; admin scope).
- [ ] Hook key-management actions (`key.create`/`key.revoke`) into `audit_log` too.
- [ ] Tests: delete moves row + writes audit; restore round-trips; audit before/after correctness;
      transaction rollback leaves neither orphaned data nor orphaned audit rows.

**Done when:** nothing is ever hard-deleted from business tables, every change is reconstructable
from `audit_log`, and any deleted row can be restored.

---

## Phase 6 — Containerization finalization, performance & deployment

**Goal:** production-ready, ultra-performant image (§17). Builds on the Phase 0.5 baseline.

- [ ] Finalize Apache2 vhost + FPM (`mpm_event`/`mod_proxy_fcgi`), TLS, HTTP/2, `mod_brotli`,
      deny dotfiles/`vendor/` (`apache2-infrastructure-expert`).
- [ ] **Persistent-connection capacity sizing**: enforce Σ(FPM `pm.max_children` × replicas) ≤ MySQL
      `max_connections` − headroom; document the numbers (`mysql-expert` + `apache2-infrastructure-expert`).
- [ ] OPcache + **preloading** (`opcache.preload`) of core; enable **JIT** and keep it only where
      profiling proves a win; tune realpath cache (`php-optimization-engineer` — measure first).
- [ ] Parallel-execution paths where they pay off (`parallel` ext / Fibers+amphp) for fan-out work.
- [ ] Generated `GET /api/v1/openapi.json` + contract tests (`rest-api-specialist`, `automation-tester-specialist`).
- [ ] Security review of the whole surface (authz/IDOR, injection, headers, secrets) (`php-security-engineer`).
- [ ] Integration suite against real (external-style) MySQL + Redis containers; CI gates merges on green.
- [ ] Retention/purge jobs for `api_request_log` / `audit_log` / `archived_records` (per policy).

**Done when:** `make build` produces a self-contained PHP 8.5 + Apache + Redis image that connects to
an **external** MySQL over persistent connections, passes security + contract + integration suites,
and ships an accurate OpenAPI spec.

---

## Dependency order (summary)

```text
P0 Bootstrap
   └─► P0.5 Container baseline (PHP 8.5 + Apache + Redis, external DB, pConnect)
          └─► P1 Response/Error
                 └─► P2 Generic CRUD engine
                        └─► P2.5 Plugin system (sealed core)
                               └─► P2.6 Doc generator (HTML + MD)
                                      └─► P3 Auth/Permissions ──► P4 Usage/RateLimit
                                                 └─► P5 Audit + Archival delete
                                                        └─► P6 Container/Perf finalize + Deploy
```

- Container baseline (P0.5) comes first so all later work runs in the real PHP 8.5 runtime.
- Auth (P3) gates everything exposed; archival delete (P5) must land before DELETE is enabled in P2.
- Plugins (P2.5) come before auth so features are plugin-delivered from the start.
- The doc generator (P2.6) precedes feature growth so the "docs on every change" policy holds throughout.
```text

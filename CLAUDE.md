# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project status

This is **MicroService v1.0**, a reusable **CodeIgniter 4 microservice framework** providing
essential REST CRUD over a single database. It is in the **design stage** — the architecture is
fully specified but no application code exists yet (only README, GPLv3 LICENSE, editor config,
and the specialist agents/skills).

**Read the design before building:**
- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — the system design (source of truth).
- [docs/PLAN.md](docs/PLAN.md) — the phased, dependency-ordered implementation roadmap.

When you add the first real code, update this file with the actual build/test/lint/serve commands.

## Locked architectural decisions

These are settled. Don't change one without recording the new decision in
[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) §3.

1. **Stack:** CodeIgniter **4.7.x**, PHP **8.5** (ZTS build; code stays 8.4-compatible), **MySQL 8**
   (single DB, InnoDB/utf8mb4), **Redis** for cache/rate-limit/counters, **Apache2 + PHP-FPM** (`mpm_event`).
2. **Generic, config-driven CRUD** — one generic controller+model serves every entity; resources
   are *declared*, not hand-coded. Filter/sort/field exposure are allow-lists.
3. **Auth = bearer API keys.** Many keys per service, per-key permission scopes (`{resource}:{action}`,
   wildcards), usage tracked. `Authorization: Bearer <key>`; only secret hashes stored.
4. **Responses:** wrapped `{data, meta}` on success; **RFC 9457 Problem Details** on error.
5. **Full auditing:** every request logged (access audit) and every mutation logged with
   before/after snapshots (data audit), written **inside the same DB transaction** as the change.
6. **Archival deletes:** DELETE never destroys — it moves the full row to a recycle-bin table and
   can be restored. No destructive deletes on business tables.
7. **Sealed core + plugins:** developers **never edit `app/` (core)**. All features live in
   `plugins/<Vendor>/<Name>/` and extend the core via the registry, events, and routes.
8. **Auto-generated docs:** every function/endpoint change regenerates **searchable HTML**
   (`public/docs/`) and **LLM-friendly Markdown** (`docs/api/`) from code — never hand-edited.
9. **Self-contained container, external DB:** ships as a Docker image (PHP 8.5 + Apache2 + Redis,
   OPcache/JIT/preload, parallel-capable). **No database in the container** — MySQL is external.
   **Persistent DB connections** (`pConnect`); FPM pool sized so Σ`max_children` ≤ MySQL `max_connections`.
10. **Delivery = monorepo** (core `app/` + all `plugins/` in one repo; seal enforced by CI guard),
    **deploy via docker-compose** (app + Redis sidecar, external DB), and the Phase 2 vertical slice
    is built against a **generic `products` sample** plugin.

## Standing workflow rules

- **Never modify the core (`app/`) for features.** Build in `plugins/`. If a task seems to need a
  core change, treat it as a framework change (separate concern) and flag it — don't smuggle
  feature logic into core.
- **Regenerate docs in the same change** as any new/changed endpoint: run `php spark docs:generate`
  and commit the output — OpenAPI (`public/docs/openapi.json`), searchable HTML viewer
  (`public/docs/index.html`), Markdown (`docs/api/README.md`), and the Postman collection
  (`docs/postman/`). All four are generated from the resource registry by `App\Libraries\Docs`,
  so they never drift. (Generated docs are excluded from the production image — see `.dockerignore`.)
- **Test in the same change** (see Testing policy below).
- Build in the **phase order** of [docs/PLAN.md](docs/PLAN.md); each phase ends green.

## License

GPLv3. Keep new source files compatible with this license.

## Specialist agents & skills

This repo ships project-scoped subagents (`.claude/agents/`) and skills (`.claude/skills/`)
covering the target stack. Delegate domain work to the matching specialist:

| Domain | Agent / Skill |
| --- | --- |
| REST API design (versioning, status codes, HATEOAS, OpenAPI) | `rest-api-specialist` |
| PHP 8.4 / 8.5 language features & idioms | `php-84-85-expert` |
| MySQL schema, indexing, query tuning | `mysql-expert` |
| PHP + Redis (caching, queues, sessions, locks) | `php-redis-specialist` |
| Application security & secure coding | `php-security-engineer` |
| System design & implementation planning | `software-architect-planner` |
| Apache2 config, vhosts, performance, TLS | `apache2-infrastructure-expert` |
| PHP profiling & performance optimization | `php-optimization-engineer` |
| Writing/running PHPUnit tests per change | `phpunit-testing` |
| Test strategy across the pyramid (API/integration/E2E, CI) | `automation-tester-specialist` |

## Testing policy

**Every new or changed function ships with a test in the same change.** Use the
`phpunit-testing` skill to add/adjust the test and run it (full suite, single file, or
`--filter` a single test) before considering the work done — report the actual pass/fail
output. Use `automation-tester-specialist` for cross-cutting concerns: API/contract tests
against the OpenAPI spec, integration tests with real MySQL/Redis, CI wiring, and flake
reduction. Record the concrete test commands in this file once the PHPUnit/Composer setup
exists.

## Conventions to establish as the codebase grows

- This is a **microservice**: keep it single-responsibility, stateless where possible, with
  all shared state in MySQL/Redis rather than in process memory.
- Treat the REST contract (routes, request/response shapes, error format) as the public API —
  version it and avoid breaking changes.
- When introducing tooling (Composer, PHPUnit, a linter/static analyzer such as PHPStan or
  Psalm), record the exact commands here so future sessions can build, test, and lint without
  rediscovery.

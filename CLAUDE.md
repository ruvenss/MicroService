# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project status

This is **MicroService v1.0**, a project in its initial scaffolding stage. As of now the
repository contains only a README, a GPLv3 LICENSE, and editor config — there is no
application source, build tooling, dependency manifest, or test suite yet.

The intended stack (inferred from the project's specialist agents/skills) is a **PHP 8.4/8.5
REST API microservice** backed by **MySQL** and **Redis**, served under **Apache2**, with a
strong emphasis on security and performance. When you add the first real code, update this
file with the actual build/test/lint commands and the concrete architecture.

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

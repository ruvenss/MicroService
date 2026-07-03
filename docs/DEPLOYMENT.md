# Deployment & Onboarding

Two audiences, two tracks:

- **[For infrastructure / ops](#1-infrastructure--deploy-the-service)** — stand the service up against an
  external database.
- **[For developers](#2-developers--get-a-bearer-key-and-call-the-api)** — get a bearer key and call the API.

The design rationale lives in [ARCHITECTURE.md](ARCHITECTURE.md); this doc is the operational runbook.

---

## 1. Infrastructure — deploy the service

### What ships and what doesn't

The stack (`docker-compose.yml`) is **three containers built from one image**:

| Service     | Role                                                                                  |
| ----------- | ------------------------------------------------------------------------------------- |
| `app`       | Apache (`mpm_event`) + PHP-FPM serving the REST API on port `80` (published to host). |
| `redis`     | Sidecar for the cache / rate-limit counters / idempotency store.                      |
| `scheduler` | Same image running the spark CLI: delivers queued webhooks and prunes transient tables. |

**The database is external and is never part of this stack** (ARCHITECTURE §17). You must provide a
reachable **MySQL 8** with a database and user already created. Redis *is* bundled — you don't supply it.

### Prerequisites

- Docker + Docker Compose v2.
- A MySQL 8 instance reachable from the app container, with an empty database and a dedicated user
  that can `CREATE TABLE` (the app owns its schema via migrations).
- **Connection sizing:** the FPM pool is sized so `Σ max_children` across app replicas **≤** the
  database's `max_connections` (ARCHITECTURE §17, persistent connections). Confirm headroom before
  scaling replicas.

### Steps

```sh
cp .env.example .env
```

Edit `.env` and set at least:

| Variable                            | Purpose                                                                           |
| ----------------------------------- | --------------------------------------------------------------------------------- |
| `DB_HOST`, `DB_PORT`                | Your **external** MySQL. (Defaults point at a dev DB on the host — override them.) |
| `MYSQL_DATABASE`/`_USER`/`_PASSWORD`| The DB name and app credentials. `MYSQL_PASSWORD` is **required** — compose fails without it. |
| `APP_BASE_URL`                      | Fixed public URL, e.g. `https://api.example.com/`. Used for the `Location` header on create; it is intentionally **not** taken from the request `Host`, so a spoofed header can't poison it. |
| `WEBHOOK_URL` / `WEBHOOK_SECRET`    | Optional — your n8n Webhook node + HMAC secret. Empty = webhooks disabled.        |

Then build, start, and **run migrations** (the entrypoint does *not* auto-migrate):

```sh
make up                                             # = docker compose up -d --build (app + redis + scheduler)
docker compose exec app php spark migrate --all     # creates api_keys tables + sample `products`
```

> Migrations are a deliberate manual step so a deploy never mutates schema by surprise. Re-run
> `migrate --all` after every image update that adds migrations.

### Verify the deployment

```sh
curl -s http://localhost:8080/api/v1/health         # {"data":{"status":"ok",...}} with HTTP 200
```

`/health` reports readiness **per replica** and degrades gracefully: if Redis is unreachable the app
falls back to the file cache and stays `200` — it does not 500 (see the health/readiness notes in
ARCHITECTURE). Point your orchestrator's liveness/readiness probe at this endpoint.

### Operational notes

- **Webhooks** are delivered by the `scheduler` sidecar every `DISPATCH_INTERVAL` seconds (default 30).
  Without that container, enqueued events never leave the outbox. Dead-letter replay
  (`php spark webhooks:retry`) is **manual** by design — run it after an n8n outage recovers.
- **Retention:** the scheduler runs `maintenance:prune` daily. Tune `RETENTION_*` in `.env`
  (audit log and recycle bin are never pruned). `0` = keep forever.
- **Logs:** `make logs` (or `docker compose logs -f`) tails app + scheduler.

---

## 2. Developers — get a bearer key and call the API

### Get a key (printed once)

Keys are minted with the spark CLI inside the running container. The full secret is shown **once** and
only its hash is stored — it cannot be recovered, so capture it immediately.

```sh
make key NAME="my-workflow" SCOPES="products:*,archive:read,archive:write"
# or, with full control:
docker compose exec app php spark key:create \
  --name "my-workflow" \
  --scopes "products:read,products:write" \
  --expires 2026-12-31 \
  --rate-limit 120
```

Output includes the line to copy:

```
Header: Authorization: Bearer <prefix>.<secret>
```

### Scopes — grant least privilege

Scopes are `{resource}:{action}` where action is `read` / `write` / `delete`, with `*` wildcards
(`products:*`, `*:read`). **Prefer explicit scopes.** The default `*:read` (used when `--scopes` is
omitted) also grants read of the **audit trail** and the **recycle bin**, so a leaked default key exposes
the full change history. Mint only what a workflow needs.

### Call the API

```sh
KEY="<prefix>.<secret>"

# Create
curl -s -X POST http://localhost:8080/api/v1/products \
  -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
  -d '{"sku":"SKU-1","name":"Widget","price":"9.99","status":"active"}'

# List with filter + sort
curl -s "http://localhost:8080/api/v1/products?filter[status]=active&sort=-created_at" \
  -H "Authorization: Bearer $KEY"
```

Success responses are wrapped `{data, meta}`; errors are RFC 9457 Problem Details.

### Discover what your key can do

- `GET /api/v1/_me` — introspects the key: its scopes, rate limit, status.
- `GET /api/v1/_resources` — per resource the key can reach, returns the primary key, writable schema
  (types + enums), filterable/sortable columns, default sort, page size, and bulk limits — enough for an
  n8n node to auto-build requests.

### Explore interactively

- **Postman:** import `docs/postman/MicroService.postman_collection.json` + the local environment, set
  the `apiKey` variable to your key, and run the self-verifying collection.
- **OpenAPI / Swagger UI:** the spec is `public/docs/openapi.json` (served in dev via `php spark serve`;
  excluded from the production image on purpose).

### Manage keys over their lifetime

```sh
docker compose exec app php spark key:list                            # never prints secrets
docker compose exec app php spark key:rotate <prefix> --grace-hours 24  # old + new both valid in the window
docker compose exec app php spark key:revoke <prefix>                  # takes effect immediately
```

Rotate on a schedule or on suspected exposure; the grace window lets a consumer swap keys with zero
downtime. Revoked and expired keys fail auth immediately.

---

## Local development against a throwaway DB

Don't have an external MySQL handy? Start a dev MySQL + Redis on the host, then point the stack at it
(the compose defaults already target `host.docker.internal`):

```sh
make dev-db                                          # docker-compose.dev.yml: MySQL 8 + Redis
make up
docker compose exec app php spark migrate --all
```

`make test` / `make stan` / `make cs` run the host quality gates.

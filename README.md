# MicroService v1.0

A reusable **CodeIgniter 4 microservice** that turns a single database into a secure,
generic REST API — declare a resource and get filtered/sorted/paginated CRUD, bearer-key
auth with scopes, idempotent writes, full auditing, archival deletes, and outbound
webhooks, all config-driven. Built to sit behind **n8n** and to **never reveal the engine**
if exposed.

The design is in **[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)** (source of truth) and the
roadmap in **[docs/PLAN.md](docs/PLAN.md)**.

## Quickstart

Runs as a Docker image (PHP 8.5 + Apache + Redis); **the database is external**.

```sh
cp .env.example .env            # then set MYSQL_* (and WEBHOOK_URL for n8n, optional)

make dev-db                     # optional: a local MySQL + Redis to develop against
make up                         # build + start the app (+ Redis sidecar) on :8080
docker compose exec app php spark migrate --all   # create the sample `products` table

curl -s http://localhost:8080/api/v1/health        # {"data":{"status":"ok",...}}
```

Mint an API key (printed **once**):

```sh
make key NAME="local" SCOPES="products:*,audit:read,archive:read,archive:write"
# → prefix.secret   — use it as:  Authorization: Bearer <prefix.secret>
```

Try it:

```sh
KEY="<prefix.secret>"
curl -s -X POST http://localhost:8080/api/v1/products \
  -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
  -d '{"sku":"SKU-1","name":"Widget","price":"9.99","status":"active"}'

curl -s "http://localhost:8080/api/v1/products?filter[status]=active&sort=-created_at" \
  -H "Authorization: Bearer $KEY"
```

## Test every endpoint as a human

- **Postman:** import `docs/postman/MicroService.postman_collection.json` and the environment
  `docs/postman/MicroService.local.postman_environment.json`; set the `apiKey` variable to your
  key. Bearer auth is inherited by every request; a create captures the new id so the
  create → show → update → delete chain runs in order, and a **List** seeds the id from the
  first row when unset — so the recycle-bin chain (List archive → inspect → restore, which
  targets `{{archiveId}}`) is runnable straight after import without copying ids by hand.
  Unique fields (e.g. `sku`) use Postman's `{{$randomUUID}}`, so you can **re-run the whole
  collection repeatedly** without hitting duplicate-value errors.
- **OpenAPI / Swagger UI:** the spec is `public/docs/openapi.json` (served via `php spark serve`
  in dev; excluded from the production image on purpose). It documents request **and** response
  schemas, error codes, and the `X-RateLimit-*` / `ETag` / `Link` headers.
- **Discovery:** `GET /api/v1/_resources` returns, per resource the key can access, its writable
  schema (types + enums), filter/sort columns, `upsertKey`, `perPage`, and `bulkMax` — enough for
  an n8n node to auto-build requests. `GET /api/v1/_me` introspects the key itself.

## API keys

```sh
docker compose exec app php spark key:list
docker compose exec app php spark key:rotate <prefix> --grace-hours 24   # old + new both valid in the window
docker compose exec app php spark key:revoke <prefix>
```

## Extend (never edit the core)

Features live in `plugins/<Vendor>/<Name>/`, never in `app/` (a CI guard enforces it):

```sh
docker compose exec app php spark make:plugin Acme/Billing
docker compose exec app php spark migrate --all      # create its table
docker compose exec app php spark docs:generate      # refresh OpenAPI / Postman / Markdown
```

The new resource is immediately discoverable and CRUD-able. See ARCHITECTURE §15.

## Development

```sh
make test        # composer test  (PHPUnit)
make stan        # composer stan  (PHPStan, strict)
make cs          # composer cs    (PHPCS, PSR-12)
docker compose exec app php spark docs:check   # fail if committed docs drift from code
docker compose exec app php spark guard:seal   # fail if the core references a plugin
```

## License

GPLv3 — see [LICENSE](LICENSE).

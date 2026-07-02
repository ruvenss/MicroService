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
make up                         # build + start app + Redis + scheduler sidecar on :8080
docker compose exec app php spark migrate --all   # create the sample `products` table

curl -s http://localhost:8080/api/v1/health        # {"data":{"status":"ok",...}}
```

Mint an API key (printed **once**):

```sh
make key NAME="local" SCOPES="products:*,audit:read,archive:read,archive:write"
# → prefix.secret   — use it as:  Authorization: Bearer <prefix.secret>
```

Scopes are `{resource}:{action}` (`read`/`write`/`delete`) with wildcards. Prefer **explicit** scopes:
the default `*:read` (used when `--scopes` is omitted) also grants read of the **audit trail** (`_audit`)
and **recycle bin** (`_archive`), so a leaked default key exposes the full change history — mint only
what a workflow needs.

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
  collection repeatedly** without hitting duplicate-value errors — every request, including the
  destructive bulk delete (which creates its own throwaway row to delete), runs green top-to-bottom.
  Every request is **self-verifying**: it asserts its documented success status and `{data}`/`{meta}`
  envelope, so the Postman runner (or `newman run`) shows a green check per endpoint — a lightweight
  smoke/contract test you can point at any deployment, not just a list of status codes.
- **OpenAPI / Swagger UI:** the spec is `public/docs/openapi.json` (served via `php spark serve`
  in dev; excluded from the production image on purpose). It documents request **and** response
  schemas, error codes, and the `X-RateLimit-*` / `ETag` / `Link` headers. The server URL is a
  templated `{scheme}://{host}` — in Swagger UI set **host** (and **scheme**) to your deployment to
  try endpoints against it without editing the spec.
- **Discovery:** `GET /api/v1/_resources` returns, per resource the key can access, its `primaryKey`,
  writable schema (types + enums), filter/sort columns, `defaultSort`, `upsertKey`, `perPage`, and
  `bulkMax` — enough for an n8n node to auto-build requests. `GET /api/v1/_me` introspects the key itself.

## Connect to n8n

Two directions:

- **n8n → API (pull):** an n8n **HTTP Request** node calls `/api/v1/{resource}` with
  `Authorization: Bearer <key>`; use `_resources` (above) to auto-build the request, cursor pagination
  (`?cursor=`) or the `Link` header to page, and `filter[updated_at][gte]=<ISO>` for incremental sync
  (any ISO-8601 form n8n emits — `Z`, a `+02:00` offset, fractional seconds — is normalised to UTC
  server-side, so the comparison is correct regardless of your MySQL server's time zone).
- **API → n8n (push):** set `WEBHOOK_URL` in `.env` to your n8n **Webhook** node's URL. On every
  create/update/delete the service enqueues a signed event to a transactional outbox, and the
  **scheduler sidecar** delivers it automatically (within `DISPATCH_INTERVAL`, default 30 s) — no cron
  to wire up. Each POST carries `X-Event: {resource}.{action}`, a stable `X-Webhook-Id` (dedupe), and,
  when `WEBHOOK_SECRET` is set, `X-Signature: sha256=<hex>` = `HMAC-SHA256(rawBody, secret)` — strip the
  `sha256=` prefix and compare in a Function node to verify authenticity. **Verify over the RAW body
  bytes** exactly as received: enable the n8n Webhook node's *raw body* option and HMAC that string — do
  **not** re-`JSON.stringify()` the parsed object, whose bytes (key order, spacing, and `\u`-escaping of
  UTF-8) won't match what was signed. The service emits raw UTF-8 with unescaped slashes and signs those
  exact bytes, so `hmac_sha256(rawBody, secret)` reproduces the digit-for-digit hex.

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

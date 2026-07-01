# MicroService API reference

> Generated from the resource registry by `php spark docs:generate` — do not edit by hand.

All `/api/v1/*` endpoints require `Authorization: Bearer <prefix>.<secret>` except **health**. Success responses are `{ "data": ..., "meta": ... }`; errors are RFC 9457 `application/problem+json`. Every response carries `X-Request-Id`; rate limits surface via `X-RateLimit-*` and `429`. Reads carry an `ETag` — resend it as `If-None-Match` to get `304 Not Modified` when nothing changed. Responses expose no PHP/CodeIgniter/Apache fingerprint.

## System

### `GET /api/v1/health`

- **Summary:** Health probe: readiness (default, checks database + cache; 503 if degraded) or liveness.
- **Auth:** none (open)
- **Scope:** —
- **Query params:**
  - `probe` — live = process-only liveness (always 200, no dependencies); ready (default) = readiness, pings database + cache.
- **Success:** `200` (`item` envelope)
- **Conditional:** returns an `ETag`; resend it as `If-None-Match` for `304 Not Modified`.

### `GET /api/v1/_me`

- **Summary:** Introspect the authenticated API key (name, scopes, limits) — no secret.
- **Auth:** bearer
- **Scope:** —
- **Success:** `200` (`item` envelope)
- **Conditional:** returns an `ETag`; resend it as `If-None-Match` for `304 Not Modified`.

### `GET /api/v1/_resources`

- **Summary:** Discover registered resources and how to query them.
- **Auth:** bearer
- **Scope:** —
- **Success:** `200` (`collection` envelope)
- **Conditional:** returns an `ETag`; resend it as `If-None-Match` for `304 Not Modified`.

## Audit & recycle bin

### `GET /api/v1/_archive`

- **Summary:** List archived (deleted) rows.
- **Auth:** bearer
- **Scope:** archive:read
- **Query params:**
  - `resource` — Optional resource filter.
  - `page` — Page number (1-based).
  - `perPage` — Items per page (capped per resource).
- **Success:** `200` (`collection` envelope)
- **Conditional:** returns an `ETag`; resend it as `If-None-Match` for `304 Not Modified`.

### `GET /api/v1/_archive/{id}`

- **Summary:** Inspect one archived record (full payload).
- **Auth:** bearer
- **Scope:** archive:read
- **Path params:** `id`
- **Success:** `200` (`item` envelope)
- **Conditional:** returns an `ETag`; resend it as `If-None-Match` for `304 Not Modified`.

### `POST /api/v1/_archive/{id}/restore`

- **Summary:** Restore an archived record to its original table.
- **Auth:** bearer
- **Scope:** archive:write
- **Path params:** `id`
- **Success:** `200` (`item` envelope)

### `GET /api/v1/_audit`

- **Summary:** Read the data-mutation audit trail.
- **Auth:** bearer
- **Scope:** audit:read
- **Query params:**
  - `resource` — Optional resource filter.
  - `record_id` — Optional record id filter.
  - `page` — Page number (1-based).
  - `perPage` — Items per page (capped per resource).
- **Success:** `200` (`collection` envelope)
- **Conditional:** returns an `ETag`; resend it as `If-None-Match` for `304 Not Modified`.

## Products

### `GET /api/v1/products`

- **Summary:** List products.
- **Auth:** bearer
- **Scope:** products:read
- **Query params:**
  - `page` — Page number (1-based).
  - `perPage` — Items per page (capped per resource).
  - `cursor` — Opt into keyset pagination (stable, index-fast, ideal for n8n). Send the param empty to start, then follow meta.pagination.nextCursor until it is null. Iterates by id; combine only with sort=id / -id.
  - `sort` — Sort column; prefix "-" for descending. Allowed: sku, name, price, created_at.
  - `fields` — Comma-separated sparse fieldset. Allowed: id, sku, name, price, status, created_at, updated_at.
  - `filter[sku]` — Filter. Columns: sku, status, price. Operators: eq, ne, gt, gte, lt, lte, like, in (e.g. filter[col][gte]=10).
- **Success:** `200` (`collection` envelope)
- **Conditional:** returns an `ETag`; resend it as `If-None-Match` for `304 Not Modified`.

### `POST /api/v1/products`

- **Summary:** Create a products record (send a JSON array of objects to bulk-create, all-or-nothing).
- **Auth:** bearer
- **Scope:** products:write
- **Body (JSON):**
  - `sku` (string, required)
  - `name` (string, required)
  - `price` (number, required)
  - `status` (string, optional)
- **Success:** `201` (`item` envelope)

### `PATCH /api/v1/products`

- **Summary:** Bulk update products: a JSON array of objects, each with its id plus fields to change (all-or-nothing, max 100).
- **Auth:** bearer
- **Scope:** products:write
- **Body (JSON example):**

```json
[
    {
        "id": "1",
        "sku": "string",
        "name": "string",
        "price": "0.00",
        "status": "string"
    }
]
```
- **Success:** `200` (`collection` envelope)

### `DELETE /api/v1/products`

- **Summary:** Bulk archival delete products: send {"ids": [...]} (all-or-nothing, max 100, restorable via the recycle bin).
- **Auth:** bearer
- **Scope:** products:delete
- **Body (JSON example):**

```json
{
    "ids": ["1", "2"]
}
```
- **Success:** `200` (`meta` envelope)

### `GET /api/v1/products/{id}`

- **Summary:** Fetch one products by id.
- **Auth:** bearer
- **Scope:** products:read
- **Path params:** `id`
- **Success:** `200` (`item` envelope)
- **Conditional:** returns an `ETag`; resend it as `If-None-Match` for `304 Not Modified`.

### `PATCH /api/v1/products/{id}`

- **Summary:** Update a products (applies the fields sent).
- **Auth:** bearer
- **Scope:** products:write
- **Path params:** `id`
- **Body (JSON):**
  - `sku` (string, required)
  - `name` (string, required)
  - `price` (number, required)
  - `status` (string, optional)
- **Success:** `200` (`item` envelope)

### `PUT /api/v1/products/{id}`

- **Summary:** Update a products (PUT alias of the update operation — applies the fields sent; clients that default to PUT can use it).
- **Auth:** bearer
- **Scope:** products:write
- **Path params:** `id`
- **Body (JSON):**
  - `sku` (string, required)
  - `name` (string, required)
  - `price` (number, required)
  - `status` (string, optional)
- **Success:** `200` (`item` envelope)

### `DELETE /api/v1/products/{id}`

- **Summary:** Archival delete (moves the row to the recycle bin).
- **Auth:** bearer
- **Scope:** products:delete
- **Path params:** `id`
- **Success:** `204` (no body)


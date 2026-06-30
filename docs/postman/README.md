# Postman collection

Importable API collection for humans to test every endpoint by hand.

## Import

1. In Postman: **Import** → drop both files:
   - `MicroService.postman_collection.json` — the requests.
   - `MicroService.local.postman_environment.json` — the `baseUrl` / `apiKey` variables.
2. Select the **MicroService — Local** environment (top-right).
3. Set `apiKey` to a valid key once auth lands (the `Health` request needs none).
4. Send **System → Health** — you should get `200` with `{"data":{"status":"ok"...}}`.

## Notes

- **Auth:** bearer API key is configured at the collection level, so new requests
  inherit it automatically. This mirrors how an **n8n** HTTP Request node should be
  configured (Authentication → Generic → Header/Bearer).
- **Contract:** success responses are `{ "data": ..., "meta": ... }`; errors are
  RFC 9457 `application/problem+json`.
- **Stealth:** responses intentionally expose no engine fingerprint — the bundled
  test script asserts `Server: MicroService` and the absence of `X-Powered-By`.
- This collection is hand-maintained for now; once the doc generator lands
  (PLAN Phase 2.6) it will be produced from the OpenAPI spec automatically.

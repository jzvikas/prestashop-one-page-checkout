# ADR-0044: Explicit HTTP body capture for active fallback runtime

## Status

Accepted for test infrastructure only. Production checkout behavior is unchanged and `INTEGRATION_SHELL_READY` remains `false`.

## Context

The executed PrestaShop 9.1.5 installed runtime at commit `d46b47b86a153d9a76b181b86e76b5c5bf538133` proved the active Chromium checkout, finalization preflight, concurrent preflight and fully-orderable two-tab reservation contention path before the PHP/cURL active-fallback contract ran. The later fallback harness then received HTTP 200 for `/order` with `text/html` but reported a zero-byte body.

That evidence does not justify changing production OPC takeover, payment, carrier, identity or finalization code. A browser in the same installed shop and server lifecycle had just rendered the real checkout successfully. The unresolved boundary is the PHP/cURL harness response capture or the server response as observed by that client.

## Decision

The persistent fallback HTTP session captures response bodies through `CURLOPT_WRITEFUNCTION` instead of relying on `CURLOPT_RETURNTRANSFER`.

Each request also reasserts GET semantics with `CURLOPT_HTTPGET` and records libcurl's downloaded-byte and content-length metadata. Failure diagnostics remain structural only: HTTP status, path without query data, content type, captured byte count, libcurl transfer byte count, content length and coarse checkout markers. Response bodies, cookies, CSRF tokens, customer data and credential-bearing headers are never emitted.

This is an evidence-gathering/runtime-correctness change. It is not a production compatibility workaround and it does not alter the real Core checkout request path.

## Safety invariants

- One persistent `CurlHandle` still owns the complete cart/checkout/failure/recovery session.
- Core `/cart?add=1&ajax=1` remains the only cart seeding path used by this HTTP contract.
- No payment form is submitted and no order is created.
- The runtime harness never calls `validateOrder()` or bypasses Core payment/carrier mechanics.
- Failure injection remains confined to `/tmp/jzopc-active-fixture*`.
- Diagnostics must not log response bodies, cookie values, tokens, form values or customer information.
- `INTEGRATION_SHELL_READY=false` remains unchanged in production source.

## Verification requirement

Source/smoke success alone does not prove the fallback runtime milestone. The installed PrestaShop 9.1.5 gate must execute again. If `transfer_bytes` is positive and `captured_bytes` is also positive, the previous zero-body symptom was a harness capture problem and the full healthy/failure/recovery matrix must then pass before this milestone is considered verified. If libcurl itself still reports zero downloaded bytes, the next investigation stays on the server/request-lifecycle boundary rather than changing production OPC code speculatively.

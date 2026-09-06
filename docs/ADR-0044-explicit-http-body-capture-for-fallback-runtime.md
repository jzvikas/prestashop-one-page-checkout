# ADR-0044: Explicit HTTP body capture for active fallback runtime

## Status

Accepted for test infrastructure only. Production checkout behavior is unchanged and `INTEGRATION_SHELL_READY` remains `false`.

## Context

The executed PrestaShop 9.1.5 installed runtime before this change proved the active Chromium checkout, finalization preflight, concurrent preflight and fully-orderable two-tab reservation contention path before the PHP/cURL active-fallback contract ran. The later fallback harness then received HTTP 200 for `/order` with `text/html` but reported a zero-byte body.

The follow-up explicit `CURLOPT_WRITEFUNCTION` diagnostic removed ambiguity about PHP return-value capture: the executed 9.1.5 runtime still reported both `captured_bytes=0` and libcurl `transfer_bytes=0`. That means the body was not merely lost after transfer. A browser in the same installed shop and server lifecycle had already rendered the real checkout successfully, so that evidence still does not justify changing production OPC takeover, payment, carrier, identity or finalization code.

The remaining suspect boundary is the request mode/state of the reused libcurl handle or the server response as observed by that client.

## Decision

The active fallback runtime keeps one persistent `CurlHandle` because its in-memory cookie engine is the intended cart/session boundary, but each request now explicitly re-establishes response-body GET semantics before execution:

- `CURLOPT_NOBODY` is set to `false`;
- `CURLOPT_HEADER` is set to `false`;
- `CURLOPT_RETURNTRANSFER` is set to `false` because body ownership belongs to the explicit write callback;
- `CURLOPT_HTTPGET` is set to `true`;
- `CURLOPT_WRITEFUNCTION` captures the response body.

This prevents stale HEAD/no-body/header-only state on a reused handle from being silently interpreted as a valid HTTP 200 checkout with zero transferred body bytes.

Each request continues to record libcurl's downloaded-byte and content-length metadata. Failure diagnostics remain structural only: HTTP status, path without query data, content type, captured byte count, libcurl transfer byte count, content length and coarse checkout markers. Response bodies, cookies, CSRF tokens, customer data and credential-bearing headers are never emitted.

This is a test-harness request-lifecycle correction. It is not a production compatibility workaround and it does not alter the real Core checkout request path.

## Safety invariants

- One persistent `CurlHandle` still owns the complete cart/checkout/failure/recovery session.
- Core `/cart?add=1&ajax=1` remains the only cart seeding path used by this HTTP contract.
- Response-body request modes are explicitly reset on every request rather than inherited implicitly from the previous transfer.
- No payment form is submitted and no order is created.
- The runtime harness never calls `validateOrder()` or bypasses Core payment/carrier mechanics.
- Failure injection remains confined to `/tmp/jzopc-active-fixture*`.
- Diagnostics must not log response bodies, cookie values, tokens, form values or customer information.
- `INTEGRATION_SHELL_READY=false` remains unchanged in production source.

## Verification requirement

The exact-head static CI for commit `d05e47a6e05d927a3786211feb6e59e3d38a86b5` executed successfully, including the source regression that requires the explicit no-body/header/return-transfer/GET reset sequence.

That does not prove the installed fallback runtime milestone. The exact-head PrestaShop runtime matrix must still execute the active 9.1.5 fallback contract. The milestone is verified only when healthy OPC, injected persistence/service/template/assets Core fallback, and same-session recovery all execute successfully. If libcurl still reports zero downloaded bytes after these explicit request-mode resets, the next change must continue to isolate the HTTP harness transport/session boundary rather than changing production OPC code speculatively.

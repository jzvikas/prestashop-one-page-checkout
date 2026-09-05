# ADR-0028: Controlled active Chromium takeover contract

## Status

Accepted for runtime/browser test infrastructure. Repository production readiness stays closed. A successful source/smoke run is not browser evidence; each Playwright step must execute successfully on every configured runtime family before it counts as verification.

## Context

Installed PHP contracts can prove service graphs, Core object identity, Smarty rendering and fail-closed HTTP behavior, but they cannot prove that the real browser loads every OPC asset, mounts exactly one checkout root, preserves trusted bootstrap values, intercepts native Core forms and remains dormant when a request falls back to Core checkout.

The temporary active fixture from ADR-0027 makes it possible to exercise those properties without changing production readiness source.

Final-submit safety also needs an active-browser negative case before any payment compatibility fixture is trusted: an incomplete cart must not be able to acquire a finalization reservation merely by calling the real module endpoint with otherwise valid CSRF/cart/state bindings.

## Decision

1. Add a pinned Playwright development dependency under `tests/Browser`; no application runtime dependency is introduced.
2. Runtime CI installs only the pinned browser-test dependency and Chromium for these contracts.
3. The takeover browser test is restricted to `JZOPC_ACTIVE_FIXTURE_ROOT=/tmp/jzopc-active-fixture*` and an explicit HTTP(S) base URL.
4. A real Core cart is created through `/cart?add=1&id_product=...`, then Chromium navigates to `/order`.
5. Healthy active checkout must contain exactly one `[data-jzopc-checkout]` root, a non-empty server state version/CSRF token, fresh `data-jzopc-finalization-reserved="0"`, and same-origin module endpoints for identity/address/address-save/carrier/payment/agreements/finalization.
6. Chromium must actually load all current checkout JavaScript assets successfully:
   - `payment-controller.js`;
   - `checkout-mutation-client.js`;
   - `final-submit-controller.js`;
   - `ordinary-payment-submit-guard.js`;
   - `binary-payment-controller.js`;
   - `payment-handoff-ambiguity-guard.js`.
7. The takeover test installs lifecycle listeners before page scripts run and requires the real `jzopc:checkout:initialized` event to carry the rendered state version.
8. It submits the Core create-identity form with browser validation disabled only to reach the guarded server mutation boundary. The resulting server validation failure must stay on `/order`, keep the same cart binding and keep the Core create form available.
9. A test-only shell-service failure marker is then activated. The next `/order` navigation must contain no OPC root, must render Core's native personal-information checkout step, and must not initialize OPC JavaScript.
10. Removing the marker and navigating again must restore healthy OPC on the same Core browser cart.
11. `finalization-preflight-browser-contract.mjs` creates a separate real Core cart/browser session, reads the active OPC root's real CSRF/cart/state/finalization bindings and sends `finalizationAction=begin` directly to the real endpoint with a cryptographically random valid-format attempt ID.
12. Because customer identity is intentionally incomplete, that request must fail closed with `success=false` and `customer_required`; HTTP 5xx, malformed JSON or success are contract failures.
13. Reloading `/order` after the rejected begin must preserve the same Core cart and render `data-jzopc-finalization-reserved="0"`, proving failed preflight did not leak/acquire the DB reservation.
14. Any page JavaScript exception fails either browser contract.
15. Neither browser contract calls `PaymentModule::validateOrder()`, creates an order, writes Core cart/order SQL directly or crosses a native payment handoff.
16. The browser tests run before the active HTTP fallback contract, because the latter cleans up the runtime product/config fixture.

## Consequences

A green takeover Chromium step proves real FO asset loading, trusted bootstrap/mounting, recoverable identity validation, request-local native fallback and same-cart recovery in a genuine browser. A green finalization-preflight Chromium step additionally proves that valid transport/security bindings alone cannot bypass server final-order eligibility and that a rejected incomplete checkout does not leave a duplicate-handoff reservation behind.

These negative-path tests intentionally stop before payment/order creation. They do not prove representative payment completion, carrier diversity, free-order completion, concurrent successful preflight attempts, reservation TTL/Core cleanup or full identity/address success flows.

## Verification state

The two browser contracts are wired into the installed PrestaShop 9.0/9.1/9.2 runtime workflow. GitHub Actions quota is currently exhausted, so the newly added finalization-preflight Playwright step has not executed and must not be treated as passing. Production `INTEGRATION_SHELL_READY` remains `false`.

## Verification remaining after this contract

Representative payment modules still require controlled redirect/embedded/binary/ordinary-form testing, including direct-submit interception, thrown/partial handlers and reservation recovery. Carrier/no-carrier, full identity/account/login, address editing, concurrent tabs, zero-total orders and accessibility/responsive release checks also remain before `INTEGRATION_SHELL_READY` can be opened.

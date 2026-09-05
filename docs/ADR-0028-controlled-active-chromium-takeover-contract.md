# ADR-0028: Controlled active Chromium takeover contract

## Status

Accepted for runtime/browser test infrastructure. Repository production readiness stays closed. A successful source/smoke run is not browser evidence; the Playwright step itself must execute successfully on each configured runtime family.

## Context

Installed PHP contracts can prove service graphs, Core object identity, Smarty rendering and fail-closed HTTP behavior, but they cannot prove that the real browser loads every OPC asset, mounts exactly one checkout root, preserves trusted bootstrap values, intercepts native Core forms and remains dormant when a request falls back to Core checkout.

The temporary active fixture from ADR-0027 makes it possible to exercise those properties without changing production readiness source.

## Decision

1. Add a pinned Playwright development dependency under `tests/Browser`; no application runtime dependency is introduced.
2. Runtime CI installs only the pinned browser-test dependency and Chromium for this contract.
3. The browser test is restricted to `JZOPC_ACTIVE_FIXTURE_ROOT=/tmp/jzopc-active-fixture*` and an explicit HTTP(S) base URL.
4. A real Core cart is created through `/cart?add=1&id_product=...`, then Chromium navigates to `/order`.
5. Healthy active checkout must contain exactly one `[data-jzopc-checkout]` root, a non-empty server state version/CSRF token, fresh `data-jzopc-finalization-reserved="0"`, and same-origin module endpoints for identity/address/address-save/carrier/payment/agreements/finalization.
6. Chromium must actually load all current checkout JavaScript assets successfully:
   - `payment-controller.js`;
   - `checkout-mutation-client.js`;
   - `final-submit-controller.js`;
   - `ordinary-payment-submit-guard.js`;
   - `binary-payment-controller.js`;
   - `payment-handoff-ambiguity-guard.js`.
7. The test installs lifecycle listeners before page scripts run and requires the real `jzopc:checkout:initialized` event to carry the rendered state version.
8. It submits the Core create-identity form with browser validation disabled only to reach the guarded server mutation boundary. The resulting server validation failure must stay on `/order`, keep the same cart binding and keep the Core create form available.
9. A test-only shell-service failure marker is then activated. The next `/order` navigation must contain no OPC root, must render Core's native personal-information checkout step, and must not initialize OPC JavaScript.
10. Removing the marker and navigating again must restore healthy OPC on the same Core browser cart.
11. Any page JavaScript exception fails the contract.
12. The browser contract does not invoke final-submit, finalization actions, `PaymentModule`, `validateOrder()` or order creation.
13. The browser test runs before the active HTTP fallback contract, because the latter cleans up the runtime product/config fixture.

## Consequences

A green Chromium step proves real FO asset loading, trusted bootstrap/mounting, recoverable identity validation, request-local native fallback and same-cart recovery in a genuine browser. It does not yet prove production payment completion, carrier diversity, free-order completion or every concurrency/TTL scenario.

## Verification remaining after this contract

Representative payment modules still require controlled redirect/embedded/binary/ordinary-form testing, including direct-submit interception, thrown/partial handlers and reservation recovery. Carrier/no-carrier, full identity/account/login, address editing, concurrent tabs, zero-total orders and accessibility/responsive release checks also remain before `INTEGRATION_SHELL_READY` can be opened.

# ADR-0030 — Controlled active Chromium takeover contract

## Status

Accepted for pre-readiness verification. Browser execution is still pending.

## Context

ADR-0029 adds a real Front Office HTTP request-path matrix for healthy takeover, integration failure, Core-native fallback and same-cart recovery. HTTP response inspection is necessary but is not sufficient to prove the browser integration shell:

- checkout JavaScript assets may fail to load even when server HTML is correct;
- trusted bootstrap URLs may target the wrong host/port;
- the checkout mutation client may reject bootstrap data and remain dormant;
- JavaScript may throw during initialization;
- a guarded state-changing identity request may fail to reach the real server mutation boundary;
- a recoverable validation failure may navigate away, lose the cart binding or destroy the Core identity form;
- a native-fallback response may still accidentally initialize OPC controllers if assets were registered before the later shell failure.

Production `INTEGRATION_SHELL_READY` must remain closed while these browser properties are unverified.

## Decision

1. Browser verification uses the same disposable active fixture defined by ADR-0029. It never changes the repository's production readiness constant.
2. The PrestaShop runtime shop domain is `localhost:8080`, matching the actual loopback Front Office server and browser base URL. Server-generated module links are therefore expected to remain same-origin in the browser.
3. The browser dependency is test-only and exactly pinned in `tests/Browser/package.json`. The runtime workflow installs only Chromium for this contract.
4. A standalone headless Chromium script creates its cart through the real Core `/cart` route and uses one browser context/cookie session.
5. Before page scripts execute, `page.addInitScript()` registers listeners for the module's real `jzopc:checkout:initialized` and `jzopc:checkout:validation-failed` lifecycle events. Validation observations retain only machine error codes, not form values or customer payloads.
6. Healthy active checkout must prove:
   - exactly one `[data-jzopc-checkout]` root;
   - positive server-bound cart ID;
   - non-empty server state version and CSRF token;
   - inactive fresh-cart reservation marker;
   - the initialized lifecycle event carrying the rendered state version;
   - successful loading of all five registered OPC JavaScript assets;
   - identity, address, address-save, carrier, payment, agreements and finalization endpoints all resolving to the configured browser origin and module route;
   - exactly one Core-backed identity create form and one login form for an unbound visitor;
   - no browser `pageerror` exception.
7. The browser then submits the empty Core-backed create identity form through the real OPC submit listener. Native HTML validation is disabled only for this test action so the request reaches the server validation boundary.
8. The recoverable identity-validation path must prove:
   - the server returns at least one validation error through `jzopc:checkout:validation-failed`;
   - the guarded response retains a non-empty authoritative state version;
   - the active Core cart ID is unchanged;
   - the page remains `/order` rather than performing an uncontrolled navigation;
   - the Core-backed create form remains present so the customer can correct input;
   - no browser JavaScript exception occurs.
9. The browser then activates only the disposable fixture's shell-service failure marker and reloads `/order`.
10. Failure-mode checkout must prove:
   - no OPC root;
   - the Core personal-information checkout step is present;
   - the OPC initialized lifecycle never fires, even though assets may already have been registered earlier in the request;
   - no browser JavaScript exception.
11. The marker is removed in nested and outer cleanup boundaries. Reloading the same browser/cart must restore healthy OPC and the same cart ID.
12. This browser contract does not click final submit, send finalization actions, invoke payment handlers, provide a successful identity payload or create an order/customer as part of the identity-validation case.
13. The standalone browser contract runs before the PHP active-HTTP contract because that later contract owns final product/config teardown.

## Security rationale

The browser is observation and guarded-input infrastructure, not a new checkout authority. It receives the same server-owned bootstrap already rendered to customers and is prohibited from introducing payment/order shortcuts.

The identity validation case deliberately uses an invalid empty form. Its purpose is to prove that browser serialization cannot override the trusted CSRF/cart/state bindings and that the real server endpoint owns validation. It does not provide a valid customer identity or password and therefore is not a customer-creation happy-path fixture.

The service-failure marker exists only in `/tmp/jzopc-active-fixture*`. Production integration classes remain marker-free, and the browser script validates the fixture path before writing the marker.

Requiring absence of `jzopc:checkout:initialized` during Core fallback is important defense in depth. Asset registration can legitimately happen before a later shell-preparation failure. Those scripts must remain dormant when the trusted OPC root is absent rather than attaching mutation/payment behavior to Core native checkout.

## Runtime matrix

The configured workflow runs this Chromium contract inside each existing runtime family:

- PrestaShop 9.0.3;
- PrestaShop 9.1.5;
- PrestaShop 9.2.0-beta.1.

The normal production-closed HTTP contract and installed runtime contracts execute before the disposable active fixture/browser phase.

## Verification state

The browser script, exact dependency pin, workflow wiring and source smoke contract are committed but have not executed in GitHub Actions because the branch is currently receiving no workflow checks/statuses.

The browser script has been syntax-checked locally with Node 22.16.0 after both the takeover/fallback and identity-validation additions. That validates JavaScript syntax only, not Chromium/PrestaShop behavior.

This ADR therefore records a configured gate, not passing browser evidence. `INTEGRATION_SHELL_READY` remains `false` until this and the broader successful identity/address/carrier/payment/concurrency browser matrix actually pass.

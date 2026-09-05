# PrestaShop One Page Checkout

Production-grade One Page Checkout module under active development for PrestaShop 9.x and PHP 8.4+.

> Current status: Core-backed identity, addresses, carrier, payment, agreements, finalization preflight, duplicate-handoff reservation, native ordinary/binary/free-order payment handoff, lifecycle cleanup, native-checkout integration failure containment and shop-scoped Back Office activation controls exist in source. Production checkout takeover intentionally remains disabled by `INTEGRATION_SHELL_READY=false` until the deferred installed-runtime/browser matrix is executed successfully.

## Runtime targets

- PrestaShop 9.x (`>=9.0 <10.0` while the compatibility matrix is under verification)
- PHP 8.4+
- multistore and multilingual architecture
- Classic/Hummingbird plus third-party payment/carrier compatibility

See `docs/COMPATIBILITY.md` for the exact verified/pending matrix.

## Version-aware checkout integration

The module does not hard-load one checkout API on every PrestaShop 9 release.

- PrestaShop 9.0/9.1: `actionCheckoutRender` receives Core's already-built process. The module reuses its exact `CheckoutSession`, fully prepares the OPC replacement first and assigns the reference only after preparation succeeds.
- PrestaShop 9.2+: `actionCheckoutBuildProcess` returns the isolated Core `CheckoutProcessProviderInterface` implementation only when the capability exists and the OPC shell has already been prepared successfully. Preparation failure returns no provider so Core can build native checkout.
- Native `ps_onepagecheckout` conflict detection is part of the shared activation policy.
- Unsupported or ambiguous runtime capability fails closed to native checkout.
- Required asset/process preparation failure trips a request-local circuit breaker so later hooks in the same request cannot partially take over checkout.
- `INTEGRATION_SHELL_READY=false` currently prevents all production takeover.

`CheckoutProcessBuilder::prepareShell()` completes the risky DB/template/presenter/third-party shell composition before process takeover. `CheckoutProcessBuilder::buildPrepared()` then builds a real Core `CheckoutProcess` around the exact Core session supplied by the active version path. `CheckoutShellStep` stores the prepared shell but still renders through Core `renderTemplate()`, preserving `actionCheckoutStepRenderTemplate`.

Fallback logging contains only an internal stage, exception class and numeric shop/cart identifiers; exception messages and request/payment payloads are deliberately excluded. See ADR-0027.

## Server-authoritative state and mutation safety

`PrestaShopCheckoutStateFactory` builds state from the loaded Core cart/context. The browser never supplies authoritative prices, taxes, shipping, totals, payment eligibility or canonical server selections.

Every checkout mutation runs through the shared guard/orchestrator boundary:

1. validate Core front-office CSRF;
2. bind submitted cart ID to the already-loaded server cart;
3. validate cart/customer ownership;
4. acquire the same-cart DB advisory mutex;
5. reload server-persisted payment/agreement authority;
6. reject stale state inside the lock;
7. reject non-finalization mutations while a finalization reservation is active;
8. execute fresh Core-backed validation/mutation;
9. require every dependency-mandated section before persistence;
10. rebuild the authoritative state version;
11. release the mutex in `finally`.

Browser transport adds `AbortController`, monotonic latest-intent sequencing, bounded stale retry and all-or-nothing section replacement validation.

## Checkout sections

### Identity

Guest/account creation and login use Core `CustomerForm`, `CustomerFormatter`, `CustomerPersister`, `CustomerLoginForm`, Core hashing and `PS_GUEST_CHECKOUT_ENABLED`. Authentication can rotate the front CSRF token. `PS_CART_FOLLOWING` cart replacement is treated as a full reload boundary rather than continuing writes under the old cart lock.

### Addresses

Saved-address selection is ownership checked and applied through Core `CheckoutSession`. Address create/edit uses `CustomerAddressForm`, `CustomerAddressFormatter` and `CustomerAddressPersister`, preserving country/state rules, Core validation, historization, theme markup and module-added fields.

### Delivery

Delivery uses the active Core `CheckoutSession`, preserving `actionCarrierProcess`, `displayCarrierExtraContent`, `displayBeforeCarrier` and `displayAfterCarrier`. A submitted option must exactly match a fresh Core delivery-option key and is persisted with the native address-keyed payload.

### Payment

Discovery uses Core `PaymentOptionsFinder::present()` and preserves `actionPresentPaymentOptions`, option IDs/module names, forms, actions, inputs, additional information and binary flags. Selection is fresh-server validated before it can become persisted authority.

### Agreements and summary

Legal conditions come from Core `ConditionsToApproveFinder` and require the exact fresh mandatory set. Summary delegates to Core `CartPresenter` and server-calculated totals.

## Finalization and native payment handoff

`controllers/front/finalize.php` executes finalization through the same guarded cart critical section.

Immediately before handoff, preflight revalidates:

- no order already exists for the cart;
- loaded cart-bound customer;
- stock/product/country/minimum-purchase orderability;
- delivery/invoice ownership and Core address validation;
- fresh carrier eligibility for physical carts;
- fresh payment-option eligibility;
- exact fresh mandatory agreements.

A successful begin acquires a DB-backed reservation scoped to shop/cart/state/payment plus a cryptographically random browser attempt ID. This is the cross-tab/process duplicate-handoff barrier. The effective installed/default reservation window is 15 minutes, with code-level overrides bounded to 60..3600 seconds and expiry based on database time.

An explicit release can clear only its own customer/attempt reservation and refuses to remove the barrier after Core reports an order for the cart. If Core order state cannot be determined safely, release fails closed and the bounded TTL remains the recovery path.

Ordinary payment forms retain observable module handlers by preferring jQuery `submit`, then `requestSubmit()`, then raw `HTMLFormElement.prototype.submit.call()` only as the final compatibility fallback. Once native module-owned activation has begun, a thrown handler is treated as ambiguous progress and the reservation is preserved rather than automatically released.

Binary/self-submitting options follow Core's `data-module-name` → `.js-payment-{module}` convention. Activation is capture-intercepted, preflighted, then the original module-owned control/form is replayed without synthesizing payment credentials or calling `validateOrder()` from the OPC module. Binary failures publish the same guarded validation lifecycle as ordinary checkout.

Reservation state also converges in the browser without becoming browser-authoritative. A fresh reload/back render exposes only a boolean active-reservation marker and immediately locks mutable checkout controls. If another pre-opened tab acquires the reservation later, guarded operations return the stable `finalization_in_progress` machine code; generic checkout mutations and ordinary/binary final submit all publish that failure, and the losing tab converges to the same fail-closed lock after local controller cleanup. The browser guard does not poll, release reservations, submit payment or create orders.

A locked checkout suppresses activation as well as disabling native form controls. Link-style payment activators (`a[href]`) and ARIA button surfaces are marked disabled and removed from normal tab order, while capture-phase `click` and `submit` listeners stop events only inside an already locked checkout root before third-party payment handlers or browser default navigation can run. Unlocked third-party hooks/forms keep their native lifecycle.

Zero-total carts remain Core-owned through `free_order` and `OrderConfirmationController::checkFreeOrder()`.

`actionValidateOrderAfter` removes the module's selection/reservation state after a real Core order exists. Abandoned selection rows are also bounded by opportunistic 30-day/100-row GC; expired finalization reservations use their separate bounded cleanup path.

## Back Office activation

The module configuration page exposes the existing `JZOPC_CHECKOUT_ENABLED` setting through PrestaShop `HelperForm`.

Safety rules:

- only a concrete single-shop multistore context may write the setting;
- submitted activation accepts only `0` or `1`;
- enabling is server-revalidated through the same runtime/native-conflict/readiness activation policy as checkout hooks;
- disabling is always allowed for the selected shop;
- group/all-shop contexts do not write rollout state;
- the current closed readiness gate means enabling is intentionally rejected today.

No module version bump is required for this UI because no new config key/schema/hook is introduced. See ADR-0019.

## Trusted HTML boundaries

Normal module values are escaped. Raw HTML is restricted to explicit server-rendered PrestaShop/Core/theme/module boundaries:

- native customer/login forms;
- native address form;
- carrier hook HTML;
- payment forms/additional information/top content;
- Core-formatted legal conditions;
- section HTML already produced by trusted server renderers when composing the checkout shell.

Browser strings are never concatenated directly into those raw boundaries.

## Development and tests

```bash
composer install
composer validate --strict --no-check-publish
find . -type f -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
find views/js -type f -name '*.js' -print0 | xargs -0 -r -n1 node --check
for test in tests/Smoke/*Test.php; do php "$test"; done
```

The repository contains a MariaDB-backed installed PrestaShop runtime workflow for the configured 9.0.3, 9.1.5 and 9.2 runtime families.

GitHub Actions execution is currently deferred because the repository's free Actions quota is exhausted. New PHP/JS/smoke/runtime contracts are still added, but they are not described as passing until they actually execute. The connected repository environment does not provide a local installed PrestaShop/browser runtime.

## Remaining release blockers

- `INTEGRATION_SHELL_READY` remains `false`;
- execute the latest PHP/Node/smoke/installed-runtime suite, including configured PrestaShop 9.0/9.1/9.2 jobs, after Actions quota resets and fix every failure;
- execute controlled HTTP/browser takeover and native-fallback tests, including injected DB/persistence, template/renderer/service and asset-registration failures on the 9.0/9.1 and 9.2 integration families;
- verify guest/account/login, CSRF rotation/cart restoration and native address flows in a real browser;
- verify representative redirect, embedded and binary payment modules plus failure/retry paths;
- verify thrown/partial third-party payment handlers cannot reopen an already-started handoff through automatic release;
- verify zero-total free order, two-tab finalization races, losing-tab live/reload convergence, locked link/form activation suppression, slow/abandoned-payment recovery and successful lifecycle cleanup;
- verify representative carrier modules and no-carrier transitions;
- complete responsive/accessibility/performance polish and final packaging/release matrix.

These are explicit safety gates, not production-ready claims.

## Documentation

- `ONE_PAGE_CHECKOUT_BUILD_PROMPT.md` — implementation source of truth
- `docs/ARCHITECTURE.md` — current architecture and data flow
- `docs/SECURITY.md` — current threat/control status
- `docs/COMPATIBILITY.md` — support and verification matrix
- `CHANGELOG.md` — release/repository changes
- `docs/ADR-*.md` — architecture decisions

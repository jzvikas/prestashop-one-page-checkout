# PrestaShop One Page Checkout

Production-grade One Page Checkout module under active development for PrestaShop 9.x and PHP 8.4+.

> Current status: Core-backed identity, addresses, carrier, payment, agreements, finalization preflight, duplicate-handoff reservation, native ordinary/binary/free-order payment handoff, lifecycle cleanup and shop-scoped Back Office activation controls exist in source. A real PrestaShop 9.1.5 Chromium run has completed official `ps_checkpayment` submission, Core order creation and OPC post-order cleanup. Production checkout takeover intentionally remains disabled by `INTEGRATION_SHELL_READY=false` until the remaining representative payment/carrier, identity/address, multistore, accessibility/performance and release gates are executed successfully.

## Runtime targets

- PrestaShop 9.x (`>=9.0 <10.0` while the compatibility matrix is under verification)
- PHP 8.4+
- multistore and multilingual architecture
- Classic/Hummingbird plus third-party payment/carrier compatibility

See `docs/COMPATIBILITY.md` for the exact verified/pending matrix.

## Version-aware checkout integration

The module does not hard-load one checkout API on every PrestaShop 9 release.

- PrestaShop 9.0/9.1: `actionCheckoutRender` replaces only the Core checkout process and reuses the exact active `CheckoutSession`.
- PrestaShop 9.2+: `actionCheckoutBuildProcess` returns the isolated Core `CheckoutProcessProviderInterface` implementation only when that capability exists.
- Native `ps_onepagecheckout` conflict detection is part of the shared activation policy.
- Unsupported or ambiguous runtime capability fails closed to native checkout.
- `INTEGRATION_SHELL_READY=false` currently prevents all production takeover.

`CheckoutProcessBuilder` builds a real Core `CheckoutProcess` around one module `CheckoutShellStep`. The step renders through Core `renderTemplate()`, preserving `actionCheckoutStepRenderTemplate`.

## Server-authoritative state and mutation safety

`PrestaShopCheckoutStateFactory` builds state from the loaded Core cart/context. The browser never supplies authoritative prices, taxes, shipping, totals, payment eligibility or canonical server selections.

Every checkout mutation runs through the shared guard/orchestrator boundary:

1. validate Core front-office CSRF;
2. bind submitted cart ID to the already-loaded server cart;
3. validate cart/customer ownership;
4. acquire the same-cart DB advisory mutex;
5. reload server-persisted payment/agreement authority;
6. reject stale state inside the lock;
7. execute fresh Core-backed validation/mutation;
8. require every dependency-mandated section before persistence;
9. rebuild the authoritative state version;
10. release the mutex in `finally`.

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

A successful begin acquires a DB-backed reservation scoped to shop/cart/state/payment plus a cryptographically random browser attempt ID. This is the cross-tab/process duplicate-handoff barrier. The default reservation window is 15 minutes, with code-level overrides bounded to 60..3600 seconds and expiry based on database time.

An explicit release can clear only its own customer/attempt reservation and refuses to remove the barrier after Core reports an order for the cart. If Core order state cannot be determined safely, release fails closed and the bounded TTL remains the recovery path.

Ordinary payment forms retain observable module handlers by preferring jQuery `submit`, then `requestSubmit()`, then raw `HTMLFormElement.prototype.submit.call()` only as the final compatibility fallback.

Because Core-presented `PaymentOption::form` markup can contain its own submit control, `ordinary-payment-submit-guard.js` captures ordinary native form submissions before third-party submit handlers. A direct submit is blocked until the server-backed finalization reservation has succeeded and the final-submit controller synchronously authorizes the exact selected option/form at the native handoff boundary. Authorization is one-shot and also expires after the current synchronous stack or any payment/section change. Payment form fields are not disabled or rewritten, preserving hidden successful controls, embedded fields and tokenization integrations.

The native payment runtime now includes a browser-authoritative pre-reservation direct-submit gate. Chromium attempts observable `requestSubmit()` on the real Core-presented `ps_checkpayment` form before the OPC final-submit button. That attempt must produce the submit-blocked lifecycle event, must not reach the payment module validation endpoint, must not run preflight/handoff or navigate, and the same checkout must then complete normally through the authorized final-submit path. The current delta is not considered verified until its exact-head workflow finishes successfully.

This ordinary-form guard is browser defense in depth, not server authority: low-level script-driven submission that intentionally avoids an observable submit event remains a third-party compatibility/browser-test concern and is not treated as production-proven.

Binary/self-submitting options follow Core's `data-module-name` → `.js-payment-{module}` convention. Activation is capture-intercepted, preflighted, then the original module-owned control/form is replayed without synthesizing payment credentials or calling `validateOrder()` from the OPC module.

For both ordinary and binary paths, automatic reservation release is limited to failures that are known to occur before native module-owned activation starts. Once the selected module's `submit`/`click` path has been invoked, a synchronous third-party handler error is treated as an ambiguous partial handoff: the reservation stays active and checkout controls remain frozen until successful Core cleanup or bounded TTL recovery. This avoids reopening a second payment attempt when the first handler may already have performed side effects.

Zero-total carts remain Core-owned through `free_order` and `OrderConfirmationController::checkFreeOrder()`.

`actionValidateOrderAfter` removes the module's selection/reservation state after a real Core order exists. Abandoned selection rows are also bounded by opportunistic 30-day/100-row GC; expired finalization reservations use their separate bounded cleanup path.

### Executed native payment evidence

Exact-head Native Payment Runtime `34065668822` on commit `7f9a652cadb1c68bdc799c214445c2fc76603e37` completed successfully on PrestaShop 9.1.5. Chromium used official pinned `ps_checkpayment`; the module/Core created exactly one order for the original OPC cart, Core order confirmation was reached, and the server-side cleanup probe confirmed both OPC transient tables were cleared after the real order. The OPC module itself did not call `validateOrder()` or create the order.

## Back Office activation

The module configuration page exposes `JZOPC_CHECKOUT_ENABLED` through PrestaShop `HelperForm`.

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

The repository contains MariaDB-backed installed PrestaShop runtime workflows for the configured 9.0.3, 9.1.5 and 9.2 runtime families plus a dedicated PrestaShop 9.1.5 native-payment order/cleanup workflow.

GitHub Actions quota is currently available. CI/runtime results are treated as evidence only after the exact workflow execution completes; queued or unexecuted gates are never described as passed.

## Remaining release blockers

- `INTEGRATION_SHELL_READY` remains `false`;
- complete the current exact-head pre-reservation direct-submit browser gate and fix any failure before treating it as verified;
- verify representative redirect, embedded/tokenization and binary payment modules plus failure/retry paths;
- verify visible submit/Enter-key and representative jQuery/native third-party handlers without weakening the one-shot authorization boundary;
- prove thrown/partial third-party handlers remain blocked behind the preserved reservation after native activation starts and recover only through successful Core cleanup or TTL;
- verify payment failure/retry, slow/abandoned-payment TTL recovery, zero-total free order and duplicate refresh behavior;
- verify broader account/login, CSRF rotation/cart restoration and foreign-address/IDOR browser cases;
- verify representative carrier modules, free/paid transitions and no-carrier/invalidation states;
- complete multistore browser rollout verification;
- complete responsive/accessibility/performance polish and final packaging/release matrix.

These are explicit safety gates, not production-ready claims.

## Documentation

- `ONE_PAGE_CHECKOUT_BUILD_PROMPT.md` — implementation source of truth
- `docs/ARCHITECTURE.md` — current architecture and data flow
- `docs/SECURITY.md` — current threat/control status
- `docs/COMPATIBILITY.md` — support and verification matrix
- `CHANGELOG.md` — release/repository changes
- `docs/ADR-*.md` — architecture decisions

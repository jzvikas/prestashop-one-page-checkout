# PrestaShop One Page Checkout

Production-grade One Page Checkout module under active development for PrestaShop 9.x and PHP 8.4+.

> Current status: the module now has a trusted server-generated checkout shell/bootstrap, guarded PrestaShop 9.0/9.1 and 9.2+ process adapters, Core-backed identity/address/carrier/payment/agreement flows, and a server-side finalization preflight with database-backed duplicate-handoff reservation plus native ordinary/binary/free-order payment handoff. Production takeover intentionally remains disabled by `INTEGRATION_SHELL_READY=false` until the deferred installed-runtime/browser matrix is executed successfully.

## Runtime targets

- PrestaShop 9.x (`>=9.0 <10.0` while the compatibility matrix is under verification)
- PHP 8.4+
- multistore and multilingual architecture
- Classic/Hummingbird and third-party payment/carrier compatibility

## Integration boundary

The module isolates version-specific checkout takeover instead of hard-loading APIs that do not exist on every 9.x release:

- PrestaShop 9.0/9.1: `actionCheckoutRender` replaces only the Core checkout process and reuses the exact active `CheckoutSession`;
- PrestaShop 9.2+: `actionCheckoutBuildProcess` returns the Core `CheckoutProcessProviderInterface` implementation from a 9.2-only provider path;
- native `ps_onepagecheckout` conflict detection is part of the common activation policy;
- unsupported/ambiguous capability combinations fail closed to native checkout;
- the custom process is still unreachable while `INTEGRATION_SHELL_READY=false`.

`CheckoutProcessBuilder` creates a real Core `CheckoutProcess` around one `CheckoutShellStep`. The step extends `AbstractCheckoutStep` and renders through Core `renderTemplate()`, preserving `actionCheckoutStepRenderTemplate`.

`CheckoutBrowserBootstrapFactory` exposes only trusted server-generated cart ID, CSRF token, state version and module endpoint URLs. It never exports client-authoritative totals, payment eligibility or canonical server selections.

## Server-authoritative checkout state

`PrestaShopCheckoutStateFactory` builds the canonical state only from the loaded Core cart/context. `CheckoutStateVersioner` produces the stale-state token used by every mutation.

`CheckoutMutationGuard` + `CheckoutCartMutex` enforce:

1. Core front-office CSRF validation;
2. submitted cart ID bound to the already-loaded session cart;
3. cart/customer ownership checks;
4. same-cart serialization through MySQL/MariaDB advisory locking;
5. stale-state rejection inside the lock;
6. fresh server-side revalidation before persistence/rendering.

Validated payment/agreement authority is stored only in `jzopc_checkout_selection`, scoped to shop + cart + customer. Browser values never replace this authority.

## Core-backed checkout sections

### Identity

Identity uses PrestaShop Core customer/login primitives (`CustomerForm`, `CustomerFormatter`, `CustomerPersister`, `CustomerLoginForm`, Core hashing service and `PS_GUEST_CHECKOUT_ENABLED`). The module does not implement password hashing, duplicate-account policy or authentication itself.

Core validation errors and module-added customer fields remain in native theme forms. Successful identity transitions clear payment/agreement authority. CSRF rotation and Core `PS_CART_FOLLOWING` cart restoration are handled explicitly and fail closed to a fresh order-page bootstrap when the active cart changes.

### Addresses

Saved-address selection is restricted to the cart-bound customer and applies through Core `CheckoutSession::setIdAddressDelivery()` / `setIdAddressInvoice()`.

Address add/edit uses `CustomerAddressForm`, `CustomerAddressFormatter` and `CustomerAddressPersister`, including Core country/state validation, historization, theme markup and module-added fields. Edit targets are ownership-checked before loading.

### Delivery

Delivery presentation and mutation use the active Core `CheckoutSession`, preserving `actionCarrierProcess`, `displayCarrierExtraContent`, `displayBeforeCarrier` and `displayAfterCarrier`.

Carrier changes require an exact fresh Core delivery-option key and are applied through `CheckoutSession::setDeliveryOption()`. Virtual carts omit the delivery section.

### Payment

Payment discovery uses Core `PaymentOptionsFinder::present()`, preserving `actionPresentPaymentOptions`, option IDs, module names, actions, forms, inputs, additional information and binary flags.

`views/js/payment-controller.js` only synchronizes selected payment UI and lifecycle events; it does not place orders.

Payment selection is accepted only after exact module + option-ID validation against a fresh Core presentation and is persisted only as eligibility state.

### Agreements and summary

Agreements use Core `ConditionsToApproveFinder::getConditionsToApproveForTemplate()` and require the submitted approved-key set to equal the entire fresh mandatory set.

Summary uses Core `CartPresenter`, preserving `actionPresentCart` and server-calculated totals.

## Finalization and payment handoff

`controllers/front/finalize.php` executes `CheckoutFinalizationMutation` through the same CSRF/cart/customer/stale-state/mutex boundary as other writes.

Immediately before payment handoff, `CheckoutFinalizationPreflightService` revalidates:

- no order already exists for the cart;
- loaded cart-bound customer;
- non-empty/orderable cart and minimum purchase;
- delivery/invoice address ownership and Core `AddressValidator` result;
- current physical-cart carrier against fresh Core delivery options;
- current persisted payment selection against fresh Core payment options;
- all fresh mandatory agreements.

A successful `begin` acquires a database-backed reservation scoped to the current shop/cart/state/payment and a cryptographically random browser attempt ID. This is the cross-tab/process duplicate-handoff barrier; the browser busy flag is only UX. A `release` request can release only its own attempt. Successful Core order lifecycle cleanup removes selection and reservation rows.

### Ordinary payment forms

`views/js/final-submit-controller.js` performs preflight and then hands control to the selected native payment form. It preserves observable module handlers by preferring jQuery `submit`, then `requestSubmit()`, with raw `HTMLFormElement.prototype.submit.call()` only as a last fallback. The module itself never calls `validateOrder()` from browser code.

### Binary/self-submitting payment

`views/js/binary-payment-controller.js` follows Core's `data-module-name` → `.js-payment-{module}` binary surface convention. Click/form-submit activation is intercepted in capture phase, server preflight runs first, and only a successful reservation replays the original module control/form.

The generic final button is hidden while a binary option owns final activation. Agreement gating mirrors Core behavior while preserving controls already disabled by the payment module. Successful binary preflight rejects unexpected section replacement so third-party runtime handlers/state are not destroyed immediately before replay.

### Free orders

Zero-total carts remain fully Core-owned. `PrestaShopCheckoutPaymentOptionsPresenter` calls `PaymentOptionsFinder::present(true)`, which exposes Core's `free_order` option. The normal native form handoff submits Core's `order-confirmation?free_order=1` action; `OrderConfirmationController::checkFreeOrder()` performs Core validation, duplicate detection and `PaymentFree` order creation.

See ADR-0017.

## Browser mutation transport

`views/js/checkout-mutation-client.js` activates only in the trusted module root. It sends the current CSRF/cart/state binding plus operation-specific data, aborts superseded requests when possible, rejects out-of-order responses, retries the latest intent at most once after `stale_state`, validates complete section replacement before DOM mutation and emits `jzopc:section:updated` for re-initialization.

Identity/address native forms are serialized with `FormData`, but `token`, `cartId` and `stateVersion` are reserved and cannot be overwritten by native/theme/module form fields.

Browser prices, shipping costs, totals, payment eligibility and canonical server selections are never authoritative inputs.

## Trusted HTML boundaries

Ordinary module values are escaped. Raw HTML is restricted to explicit server-rendered PrestaShop/Core/theme/module boundaries:

- native Core/theme customer/login forms;
- native Core/theme address form;
- carrier extra/before/after hook HTML;
- payment top/additional-information/forms;
- Core-formatted legal-condition HTML;
- section HTML already produced by trusted server renderers when composing the checkout shell.

Browser strings are never concatenated directly into those raw boundaries.

## Versioned persistence

Module-owned schema currently includes:

- `jzopc_checkout_selection` for canonical payment/agreement authority;
- the finalization reservation table for attempt-scoped duplicate-handoff protection.

Schema/hook lifecycle is handled through the existing module install/upgrade chain through `0.4.0`. No extra version bump is required merely for frontend compatibility code that adds no new DB/config/hook migration.

## Development setup

```bash
composer install
```

Local source checks:

```bash
composer validate --strict --no-check-publish
find . -type f -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
find views/js -type f -name '*.js' -print0 | xargs -0 -r -n1 node --check
for test in tests/Smoke/*Test.php; do php "$test"; done
```

Baseline CI targets PHP 8.4 and Node.js 22. The separate PrestaShop runtime workflow provisions real PrestaShop 9.1.5 and 9.2 runtime coverage and includes native `ps_onepagecheckout` conflict checks.

GitHub Actions execution is currently deferred because the repository's free Actions quota is exhausted. New PHP/JS/smoke/runtime contracts are still added, but they must not be described as passed until they are actually executed. The current connected-repository environment does not provide a local installed PrestaShop/browser runtime, so no unexecuted runtime result is claimed.

## Remaining release blockers

- `INTEGRATION_SHELL_READY` is intentionally still `false`;
- latest identity/address/carrier/finalization/browser changes still require installed-runtime execution after the Actions quota resets;
- PrestaShop 9.0 installed-runtime coverage and live HTTP/browser takeover coverage remain missing;
- representative redirect/embedded/binary payment modules need real browser verification;
- zero-total `free_order`, concurrent-tab reservation, failed/abandoned payment recovery and successful lifecycle cleanup need real installed/browser verification;
- Back Office checkout-flow activation UI is not implemented yet.

These are safety gates, not production-ready claims.

## Architecture records

See `docs/DISCOVERY.md`, `docs/ARCHITECTURE.md`, `docs/SECURITY.md` and ADRs under `docs/`, especially ADR-0008 through ADR-0017.

## Source of truth

Implementation requirements are defined in `ONE_PAGE_CHECKOUT_BUILD_PROMPT.md`. Repository Markdown instructions are live requirements and must be reviewed before each implementation milestone.

# Architecture

This document describes architecture that exists in the repository now. It deliberately separates implemented source contracts from runtime/browser verification that is still pending.

## 1. Integration boundary and version strategy

`jzonepagecheckout.php` remains the PrestaShop bootstrap. It owns install/upgrade/uninstall hooks, checkout hook entry points, the activation decision and the Back Office configuration entry point. Checkout/application behavior lives under `src/`.

### PrestaShop 9.0 / 9.1

`actionCheckoutRender` receives Core's active `CheckoutProcess` by reference. `LegacyCheckoutRenderAdapter` validates that process, reuses its exact `CheckoutSession`, and replaces only the process object with one built by `CheckoutProcessBuilder`.

### PrestaShop 9.2+

`actionCheckoutBuildProcess` returns `Integration\Provider\CheckoutProcessProvider` only after the 9.2 provider interface is confirmed to exist. The provider class is isolated from generic code so PrestaShop 9.0/9.1 never need to resolve the 9.2-only interface.

`CheckoutActivationPolicy` blocks unsupported capability combinations, an enabled native `ps_onepagecheckout` provider, a disabled merchant feature flag, or a closed integration readiness gate.

`INTEGRATION_SHELL_READY` is currently `false`. Therefore production takeover, module checkout assets and mutation endpoints remain fail-closed even though the underlying code exists.

## 2. Checkout process and trusted shell

`CheckoutProcessBuilder` creates a real Core `CheckoutProcess` around the active Core `CheckoutSession` and a single module `CheckoutShellStep`.

`CheckoutShellStep` extends Core `AbstractCheckoutStep` and renders through inherited `renderTemplate()`, preserving `actionCheckoutStepRenderTemplate`.

`CheckoutShellRenderer` composes module sections from the same server-owned cart/context and persisted server selections used by mutation guards. `CheckoutBrowserBootstrapFactory` exposes only trusted bootstrap values required by browser controllers:

- positive cart ID;
- Core front CSRF token;
- opaque server state version;
- server-generated identity/address/address-form/carrier/payment/agreement/finalization endpoint URLs.

No browser-authoritative totals, payment eligibility or canonical selection state is exported.

## 3. Front service-container boundary

Checkout hooks and legacy module front controllers resolve services through `Module::get()`. One canonical graph is stored in `config/common/services.yml`; root and front service files import that graph so the Symfony/module and legacy front containers do not drift.

Only intentional entry services are public. DBAL, presenters, validators and other dependencies remain private by default.

## 4. Server-authoritative checkout state

`CheckoutState` is the immutable application snapshot. `PrestaShopCheckoutStateFactory` derives identity and monetary fingerprints from the loaded Core cart/context only. `CheckoutStateVersioner` converts the canonical state into an opaque version token.

The state includes server-owned cart/customer/shop/language/currency/address/carrier identity, Core-derived cart/totals fingerprints and `CheckoutServerSelections`.

`CheckoutServerSelections` contains only already server-validated payment and agreement selections. It is never populated directly from browser state.

## 5. Selection persistence and cleanup

`DbalCheckoutServerSelectionsStore` persists validated selection authority in `jzopc_checkout_selection`, keyed by shop/cart and additionally bound to customer ID.

Persisted data is intentionally small:

- canonical payment option key;
- normalized approved agreement IDs;
- update timestamp.

It does not store prices, totals, payment credentials, payment form payloads, CSRF/auth/session tokens or customer/address PII.

Customer-binding mismatch deletes stale selection state. Successful Core order creation deletes the row immediately through `CheckoutOrderLifecycleCleanup`.

Abandoned selection state is bounded opportunistically: one save in 64 may delete at most 100 rows older than 30 days using the existing `date_upd` index. This GC never inspects or mutates Core cart/order tables.

## 6. Mutation guard, concurrency and response contract

All concrete checkout mutations run through `CheckoutMutationOrchestrator`.

The critical section is:

1. reject obviously invalid CSRF before locking;
2. acquire `CheckoutCartMutex` for the loaded cart;
3. load authoritative persisted selections;
4. rerun full CSRF/cart/customer/stale-state guard inside the lock;
5. resolve the required dependency sections for the current cart topology;
6. execute the concrete Core-backed mutation;
7. reject a successful result missing any mandatory section;
8. persist selections only for structurally complete success;
9. rebuild authoritative state/version;
10. release the lock in `finally`.

`CheckoutCartMutex` uses parameterized MySQL/MariaDB advisory locking through Doctrine DBAL.

Concrete module front controllers are POST-only and inherit the same activation gate. Current mutation endpoints include identity, saved-address selection, native address save/refresh, carrier selection, payment selection, agreements and finalization.

`CheckoutMutationResponseMapper` returns stable machine-readable errors, state versions, section HTML and optional redirect/rotated CSRF values without exposing internal stack traces.

## 7. Identity and authentication

`CheckoutIdentityService` delegates guest/account/login business rules to Core:

- `CustomerFormatter` + `CustomerForm`;
- `CustomerPersister` with the Core hashing service;
- `CustomerLoginFormatter` + `CustomerLoginForm`;
- `PS_GUEST_CHECKOUT_ENABLED`;
- `actionSubmitAccountBefore`.

The module does not hash passwords or create customers through a separate persistence path.

Successful identity transitions require a positive Core context customer equal to the active cart customer. Payment/agreement authority is cleared because group, rules, addresses, carrier/payment eligibility and legal conditions may have changed.

Core identity changes may rotate `Tools::getToken(false)`. The identity endpoint attaches a fresh token only after guarded completion; rejected requests never receive replacement CSRF material.

If Core `PS_CART_FOLLOWING` restores a different customer cart during login, AJAX continuation under the old cart mutex stops. The browser receives a redirect-only result and reloads the Core order page to establish a new trusted bootstrap for the replacement cart.

## 8. Addresses

Saved-address selection is parsed strictly, ownership checked through `Customer::customerHasAddress()`, and applied through Core `CheckoutSession::setIdAddressDelivery()` / `setIdAddressInvoice()`.

Address create/edit delegates to `CustomerAddressForm`, `CustomerAddressFormatter` and `CustomerAddressPersister`. Existing edit targets are ownership checked before loading. The native Core address-persister token is regenerated server-side. The active theme's standard address form is rendered so Core validation, country/state rules, historization and module-added address fields remain intact.

A real address mutation clears prior payment/agreement authority and refreshes every downstream affected section.

## 9. Delivery/carriers

Delivery rendering uses the active Core `CheckoutSession` and preserves:

- `actionCarrierProcess`;
- `displayCarrierExtraContent`;
- `displayBeforeCarrier`;
- `displayAfterCarrier`.

Carrier selection accepts only a bounded opaque Core option key, regenerates the fresh Core option set, reauthorizes the current delivery address and persists through Core's address-keyed `CheckoutSession::setDeliveryOption()` contract. Persisted cart state is reread to prove Core retained the requested option.

Virtual carts omit the delivery section and reject carrier mutations.

## 10. Payment and agreements

Payment discovery delegates to Core `PaymentOptionsFinder::present()`, preserving `actionPresentPaymentOptions`, option IDs/module names, actions, forms, inputs, additional information and binary flags.

A browser payment selection becomes authoritative only after an exact fresh module/option/presented-module match. It is eligibility state, not final order authorization.

Agreements delegate discovery to `ConditionsToApproveFinder::getConditionsToApproveForTemplate()`. Approval succeeds only when the submitted normalized key set exactly matches every currently required Core/module condition.

## 11. Browser mutation lifecycle

`views/js/checkout-mutation-client.js` is dormant unless a complete trusted `[data-jzopc-checkout]` bootstrap exists.

It provides:

- same-origin POST transport;
- trusted token/cart/state bindings;
- reserved bootstrap field names that native forms cannot overwrite;
- `AbortController` where available;
- monotonically increasing latest-intent sequence;
- bounded one-time retry after a server `stale_state` response;
- complete response-section prevalidation before any DOM replacement;
- `jzopc:section:updated` and checkout lifecycle events for reentrant controllers.

A slower superseded response cannot overwrite newer checkout state.

`payment-controller.js` synchronizes selected payment UI and reinitializes after payment-section replacement but never places an order.

## 12. Finalization, duplicate protection and native payment handoff

`CheckoutFinalizationMutation` runs through the same CSRF/cart/customer/stale-state/cart-mutex boundary.

`CheckoutFinalizationPreflightService` immediately revalidates before native handoff:

- no existing Core order for the cart;
- loaded cart-bound customer;
- non-empty cart, stock/product/country/minimum-quantity/minimum-purchase orderability;
- delivery and invoice ownership plus Core `AddressValidator`;
- fresh persisted carrier eligibility for physical carts;
- fresh payment-option eligibility;
- exact fresh mandatory agreements.

A successful begin reserves the handoff in module DB state using shop/cart/state/payment plus a cryptographically random browser attempt ID. The same attempt is idempotent; a competing active attempt fails closed. Release is attempt-scoped and cannot clear another attempt. Expired reservations use a bounded short-TTL cleanup path.

### Ordinary payment forms

`final-submit-controller.js` performs preflight then returns control to the selected Core-presented form. The submit lifecycle is preserved in this order:

1. jQuery `submit` trigger when present;
2. `requestSubmit()`;
3. raw `HTMLFormElement.prototype.submit.call()` only as a final compatibility fallback.

The OPC module does not call `PaymentModule::validateOrder()` as a shortcut.

### Binary/self-submitting options

`binary-payment-controller.js` follows Core's `data-module-name` → `.js-payment-{module}` surface identity. It intercepts click/form-submit activation during capture, obtains finalization reservation, then replays the exact original module-owned control/form. Unexpected section replacement immediately before replay fails closed to avoid destroying third-party runtime state.

### Free orders

A zero-total cart remains Core-owned. Core's `free_order` option points to `order-confirmation?free_order=1`; `OrderConfirmationController::checkFreeOrder()` performs validation, duplicate detection and `PaymentFree::validateOrder()`.

### Successful-order cleanup

`actionValidateOrderAfter` calls `CheckoutOrderLifecycleCleanup` only after a real order exists for the cart. Cleanup removes selection and finalization reservation state. Cleanup/logging failure is contained so it cannot turn an already-created Core order into a customer-visible payment failure.

## 13. Back Office activation and multistore

`JZOPC_CHECKOUT_ENABLED` is exposed through `BackOffice\CheckoutActivationConfigurationPage` and standard PrestaShop `HelperForm`.

Writes require exactly one selected shop. Group/all-shop context is read-only. The submitted value must be exactly `0` or `1`.

A request to enable is rechecked through the same `CheckoutCapabilityDetector` + `CheckoutActivationPolicy` used by production hooks, including the exact `INTEGRATION_SHELL_READY` value. A disable request is always allowed for the selected shop. `Configuration::updateValue()` receives explicit shop-group and shop IDs.

The current closed readiness gate therefore makes the BO switch safely non-activating: a forged or normal enable POST is rejected rather than pre-staged.

See ADR-0019.

## 14. Rendering trust boundaries

Module-owned values are escaped. Raw HTML is limited to explicit PrestaShop/Core/theme/module server-rendered boundaries:

- native customer/login forms;
- native address form;
- carrier extra/before/after hook HTML;
- payment top/additional-information/forms;
- Core-formatted legal conditions;
- already-rendered trusted section fragments when composing the shell.

Browser strings are never concatenated directly into those raw boundaries.

## 15. Verification state and next priorities

The repository contains source/smoke contracts and a MariaDB-backed installed-runtime workflow for PrestaShop 9.1.5 and 9.2.x-era capability/process/Smarty checks. Earlier runtime runs caught real integration issues, including legacy class autoload and front service-container visibility.

The latest identity/address/carrier/finalization/GC/Back Office deltas have not been executed through the full workflow because GitHub Actions quota is exhausted. PrestaShop 9.0 installed-runtime coverage and controlled live HTTP/browser coverage are still missing.

Highest priorities before activation:

1. run every deferred PHP/Node/smoke/installed-runtime check and fix all failures;
2. add/execute PrestaShop 9.0 installed-runtime coverage;
3. execute a controlled browser matrix for native fallback/takeover, guest/account/login, CSRF rotation/cart restoration, native address interaction, stale/race behavior and no-carrier states;
4. verify representative redirect/embedded/binary payment modules, zero-total free order, concurrent-tab reservation and failed/abandoned payment recovery;
5. complete responsive/accessibility/performance polish and release packaging;
6. only then reconsider `INTEGRATION_SHELL_READY`.

The module must not be described as production-ready while those gates remain open.

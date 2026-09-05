# PrestaShop One Page Checkout

Production-grade One Page Checkout module under active development for PrestaShop 9.x and PHP 8.4+.

> Current status: the module has a trusted server-generated checkout shell/bootstrap plus guarded version-specific checkout process adapters for PrestaShop 9.0/9.1 and 9.2+. Core-backed guest/account identity, login, saved-address selection, address add/edit, carrier selection, payment selection and agreement selection now have server-authoritative paths. The activation gate intentionally remains closed until the deferred installed-runtime/browser gates are executed and the final-submit/idempotency blocker is complete. While that gate is closed, the module cannot take over checkout and mutation endpoints return `checkout_unavailable`.

## Runtime targets

- PrestaShop 9.x (`>=9.0 <10.0` while the compatibility matrix is under active verification)
- PHP 8.4+
- multistore and multilingual architecture required
- Classic/Hummingbird and third-party payment/carrier compatibility required

## Architecture baseline

The module detects and isolates the checkout integration path without blindly loading version-specific APIs:

- PrestaShop 9.0/9.1: `actionCheckoutRender` replaces only the Core checkout process while preserving its current `CheckoutSession`;
- PrestaShop 9.2+: `actionCheckoutBuildProcess` returns a real `CheckoutProcessProviderInterface` implementation from a 9.2-only autoload path;
- native `ps_onepagecheckout` conflict detection remains part of the shared activation policy;
- unsupported or ambiguous capabilities fail closed to native checkout;
- `INTEGRATION_SHELL_READY` remains `false` until runtime/browser integration and final order safety are proven.

`CheckoutProcessBuilder` creates a real Core `CheckoutProcess` around one module-owned `CheckoutShellStep`. The step extends Core `AbstractCheckoutStep` and renders through `renderTemplate()`, preserving the `actionCheckoutStepRenderTemplate` lifecycle. The module-owned shell uses the same server-authoritative cart/session/selections state as AJAX mutations rather than creating a second client-side checkout model.

The trusted browser bootstrap contains only the current cart ID, Core front-office CSRF token, server state version and server-generated identity/address/address-form/carrier/payment/agreement mutation endpoint URLs. `CheckoutFrontendAssetRegistrar` registers the payment and stale-safe mutation controllers only on the order controller and only after the same activation gate passes. Existing installations receive the media hook through the idempotent `0.3.0` upgrade script.

The application layer has a canonical server-state version token, stale-state guard and conservative section dependency graph. `PrestaShopCheckoutStateFactory` builds state from the loaded server-side cart, Core cart/address checksums and Core-calculated totals. Generic mutation safety covers CSRF, cross-cart/customer binding, per-cart serialization and stale-state ordering. The JSON transport layer provides stable status/error mapping. Virtual carts are context-filtered from delivery refresh dependencies because the trusted shell intentionally contains no delivery DOM section for them.

Validated payment/agreement selections are persisted in the small module-owned `jzopc_checkout_selection` table, scoped by shop + cart and rebound to the current cart customer. The browser never supplies authoritative `CheckoutServerSelections`. `CheckoutMutationOrchestrator` loads them only after acquiring the cart mutex and saves new selections only after a successful handler returned all required refreshed sections. Identity, address or carrier transitions that can invalidate eligibility clear prior payment/agreement authority.

## Core-backed checkout sections

A fail-closed checkout section renderer registry is in place. Every requested section must have one concrete renderer; a missing renderer is a programming error rather than a successful partial checkout.

### Identity

Identity is now a concrete Core-backed section. `CheckoutIdentityService` deliberately reuses the same legacy front-office primitives used by PrestaShop checkout:

- `CustomerForm` + `CustomerFormatter` for guest/account data;
- `CustomerPersister` for guest/account persistence and Core session/cart side effects;
- `CustomerLoginForm` + `CustomerLoginFormatter` for authentication;
- `PS_GUEST_CHECKOUT_ENABLED` for the shop's guest rule;
- `actionSubmitAccountBefore` before create/guest submission;
- the Core `hashing` service for the `CustomerPersister` constructor.

The module does not implement password hashing, duplicate-account rules, password policy or authentication itself. Anonymous identity forms are the active theme's native Core customer/login forms, including module-added fields. Validation failures reuse the already-submitted Core form instances so field errors and hook-added fields are preserved without rendering a second hook/form stack.

A successful identity transition clears payment/agreement authority and refreshes identity plus every downstream section. Authentication/customer creation can rotate the front-office CSRF token, so the guarded identity response can return a fresh `Tools::getToken(false)` value; the browser replaces its in-memory/root token before the next mutation. Rejected CSRF requests never receive replacement token material.

Core `Context::updateCustomer()` may restore another customer cart when `PS_CART_FOLLOWING` applies. If the current cart ID changes during identity submission, the module does not persist or render selection state as though the replacement cart were protected by the initiating cart mutex. It returns a redirect-only successful transport response and reloads the Core order page, establishing a fresh authoritative cart/token/state bootstrap.

### Addresses

Addresses are restricted to the cart-bound customer, rechecked with `Customer::customerHasAddress()`, and formatted with Core `AddressFormat::generateAddress()`.

Saved-address changes use Core `CheckoutSession::setIdAddressDelivery()` / `setIdAddressInvoice()` rather than editing cart header IDs directly, preserving Core `Cart::updateAddressId()` side effects for per-product/customization delivery associations.

Address add/edit uses Core `CustomerAddressForm`, `CustomerAddressFormatter` and `CustomerAddressPersister`, preserving country/state validation, Core/module field validation, theme markup and used-address historization. Existing edit targets are authorized before loading. The inner Core address-persister token is regenerated server-side; native form fields cannot replace the outer OPC `token`, `cartId` or `stateVersion` binding.

### Delivery

Delivery uses a Core `CheckoutSession`, preserves `actionCarrierProcess`, `displayCarrierExtraContent`, `displayBeforeCarrier` and `displayAfterCarrier`, and skips shipping for virtual carts.

Carrier selection requires a real cart-bound customer and authorized delivery address. The submitted opaque option key must exactly exist in the fresh Core `CheckoutSession::getDeliveryOptions()` set. Real changes are applied through `CheckoutSession::setDeliveryOption()` using Core's native address-keyed payload and verified against persisted `Cart::$delivery_option`. A real carrier change clears payment/agreement authority and refreshes delivery, payment, agreements and summary.

### Payment

Payment uses Core `PaymentOptionsFinder::present()`, preserving payment-option discovery and `actionPresentPaymentOptions`, including actions, forms, inputs, additional information and binary markers.

`views/js/payment-controller.js` is re-entrant after payment-section replacement, removes old handlers, synchronizes payment forms/additional information and publishes payment lifecycle events. It deliberately does not submit payment forms itself.

Payment selection is parsed strictly and accepted only when module + option ID match a fresh Core-backed payment-option presentation. The selected value is server-persisted only as a canonical selection key; it is not final order authorization.

### Agreements and summary

Agreements use Core `ConditionsToApproveFinder::getConditionsToApproveForTemplate()`, preserving configured shop terms plus `termsAndConditions` hook contributions. Approval succeeds only when the submitted key set exactly equals the current required set.

Summary uses Core `CartPresenter`, preserving `actionPresentCart` and server-calculated totals.

## Browser mutation transport

`views/js/checkout-mutation-client.js` activates only inside the trusted module checkout root. It sends the current CSRF/cart/state binding plus operation data, aborts superseded requests, ignores out-of-order responses, retries the latest intent at most once after `stale_state`, validates the complete returned section set before DOM replacement, advances the authoritative state version and emits `jzopc:section:updated` for re-initialization.

Identity and native address forms are serialized with `FormData`; `token`, `cartId` and `stateVersion` are reserved and cannot be overwritten by native/theme/module fields. Identity submissions use the guarded server-generated identity URL. If a completed identity response contains a rotated CSRF token, the browser updates it before any next mutation. A cart-restoration response contains no old-cart section HTML and redirects to a fresh Core order-page bootstrap.

Saved-address controls use one atomic selection intent; country changes ask Core to regenerate address fields. Delivery-option radio changes use the guarded carrier endpoint through the same latest-intent-wins transport. Browser prices, shipping costs, totals, payment eligibility and canonical server selections are never authoritative inputs.

## Trusted HTML boundaries

Module-owned ordinary values are escaped. Raw HTML is limited to explicit server-side PrestaShop/Core/theme/module boundaries:

- native Core/theme customer and login forms;
- native Core/theme address form;
- carrier extra/before/after hook HTML;
- payment top/additional-information/forms;
- Core-formatted legal-condition HTML;
- section HTML already produced by the trusted server renderers when composed into the module shell.

Browser strings are not concatenated directly into these raw boundaries.

## Development setup

```bash
composer install
```

The raw source checkout expects the Composer autoloader. A release package will need the production dependencies/autoload artifacts required by an installed PrestaShop module.

## Local checks

```bash
composer validate --strict --no-check-publish
find . -type f -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
find views/js -type f -name '*.js' -print0 | xargs -0 -r -n1 node --check
for test in tests/Smoke/*Test.php; do php "$test"; done
```

Baseline CI executes source checks on PHP 8.4 and Node.js 22. The separate `PrestaShop Runtime` workflow provisions MariaDB 11.4, installs real PrestaShop 9.1.5 and 9.2.0-beta.1, installs this module through the PrestaShop CLI, and executes installed-runtime contracts. The 9.2 job also installs a pinned native `ps_onepagecheckout` revision to prove conflict detection.

At the moment, new workflow runs are intentionally deferred because the repository's GitHub Actions free quota is exhausted. Tests/runtime contracts continue to be added normally and must be executed after quota reset; no unexecuted test is described as passed. The current execution environment also does not provide a local clone/runtime in which the new identity PHP/Node/smoke/runtime checks can be executed.

## Known limitations

- the 9.0/9.1 adapter and 9.2+ provider are implemented but intentionally unreachable while `INTEGRATION_SHELL_READY=false`;
- the installed-runtime suite contains real Smarty-shell and module-front `CheckoutSession` contracts, but the identity/address/carrier-hardening updates have not yet been executed after their latest changes because the GitHub Actions quota is exhausted;
- PrestaShop 9.0 installed-runtime coverage and live HTTP/browser takeover are still missing;
- Core-backed identity, address, delivery, payment, agreements and summary sections exist, but they remain unreachable in production while checkout takeover is disabled;
- identity guest/account/login requires deferred installed-runtime/browser verification, including Core validation failures, duplicate-account/login behavior, module-added fields, CSRF rotation and `PS_CART_FOLLOWING` cart restoration;
- representative carrier/payment modules and rapid browser mutation behavior still require the controlled runtime/browser matrix;
- selection rows are removed on uninstall, but successful-order/abandoned-cart cleanup still belongs to final-submit lifecycle work;
- no final checkout preflight, submit idempotency/double-order guard or native payment handoff flow exists yet;
- Back Office checkout-flow activation UI is not implemented yet.

These limitations are intentional safety gates, not production-ready claims.

## Architecture records

See `docs/DISCOVERY.md`, `docs/ARCHITECTURE.md`, `docs/SECURITY.md` and ADRs under `docs/`, especially ADR-0008 through ADR-0016 for trusted shell/bootstrap, version-specific process, installed runtime, saved-address, carrier, native address-form and Core identity decisions.

## Source of truth

Implementation requirements are defined in `ONE_PAGE_CHECKOUT_BUILD_PROMPT.md`. Repository Markdown instructions are treated as live requirements and must be reviewed before each implementation milestone.

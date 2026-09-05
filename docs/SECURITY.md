# Security review

This document tracks checkout-specific threats, implemented controls and release-blocking gaps. It must be updated as checkout integration and final submission are added.

## Trust boundary

The browser is untrusted. The loaded PrestaShop `Context`/`Cart` is the checkout identity boundary. A submitted cart ID is only a binding assertion and is never used to load another cart. Prices, taxes, discounts, shipping price, payable total, payment eligibility, selected server checkout state and required legal conditions are server-authoritative.

## Implemented controls

### Transport and activation gates

Checkout mutations use a final shared module-front-controller gate. Requests must be `POST`; non-POST requests receive HTTP 405 with `Allow: POST`. Before a concrete mutation service can execute, the controller requires `JzOnePageCheckout::isCustomCheckoutActive()` to pass the same capability/native-conflict/configuration/integration-readiness policy used by checkout hooks. Inactive or incomplete custom checkout returns `checkout_unavailable` and performs no mutation.

The concrete `addressselection`, `paymentselection` and `agreements` controllers contain no resource authorization or checkout business rules. They collect request values, resolve narrowly exposed application services and delegate to the guarded orchestrator path.

The trusted shell/bootstrap and both version-specific checkout process adapters exist, but `INTEGRATION_SHELL_READY` deliberately remains `false`. Consequently the 9.0/9.1 process adapter, 9.2+ provider, frontend asset hook and mutation endpoints all remain unreachable in normal checkout traffic until the remaining integration gates are proven.

PrestaShop 9.2-only provider code is isolated in a dedicated autoload path. Generic module code checks for the provider interface before resolving the provider class, preventing older 9.x runtimes from loading an unavailable interface. The 9.0/9.1 adapter reuses the existing Core `CheckoutSession` from the hook-provided process rather than constructing a session from browser input.

### Trusted browser bootstrap

`CheckoutBrowserBootstrapFactory` derives initial browser state only from server-owned context: the loaded cart, `Tools::getToken(false)`, `PrestaShopCheckoutStateFactory`, persisted validated selections and PrestaShop-generated module links. The shell exposes only cart ID, CSRF token, state version and address/payment/agreement mutation endpoint URLs.

The browser client is dormant unless that complete module-owned root is present. The bootstrap does not contain client-authoritative totals, customer/address payloads, passwords, payment credentials or payment form data. The CSRF token is intentionally present in same-origin page markup for mutation requests and must never be logged.

### CSRF and cross-cart/customer binding

`CheckoutMutationGuard` requires the PrestaShop front-office token (`token`, with Core/theme-compatible `static_token` fallback), validates it with constant-time comparison, requires submitted cart ID to match the already loaded cart and verifies the context customer when the cart is customer-bound.

Concrete address/payment/agreement mutation services execute only through `CheckoutMutationOrchestrator`; future mutation/final endpoints must use the same or a stronger boundary.

### Address ownership / IDOR

`CheckoutAddressSelectionParser` accepts only normalized positive delivery/invoice identifiers plus an explicit same-address boolean. Same-address mode rejects a browser-supplied invoice id instead of accepting ambiguous authority.

`CheckoutAddressSelectionService` authorizes every submitted target with Core `Customer::customerHasAddress(cart_customer_id, address_id)`. When same-address mode reuses an existing cart delivery address that was not supplied in the request, ownership is checked again before it can become the invoice target. A missing checkout customer fails closed.

Authorized changes are applied through Core `CheckoutSession::setIdAddressDelivery()` / `setIdAddressInvoice()`. Delivery changes therefore preserve Core `Cart::updateAddressId()` side effects for per-product/customization delivery associations rather than changing only the cart header id. The service re-reads invoice state after delivery mutation because Core may synchronize a previously linked invoice address itself.

`PrestaShopCheckoutSessionProvider` never constructs a session from browser data. It uses the active server `Context`. On module front controllers it follows the Core version split: legacy `DeliveryOptionsFinder` remains the PrestaShop 9.0 path; the 9.1+ improved `DeliveryOptionsProvider` is referenced dynamically and only used when both class/feature-flag capability checks succeed.

A real address change invalidates persisted payment/agreement selections before refreshed sections are rendered. This prevents prior approval/eligibility from being treated as authoritative under a potentially different country, tax, carrier or payment context.

### Stale state and same-cart races

Every guarded mutation requires the prior `stateVersion`. `CheckoutCartMutex` serializes the cart critical section through parameterized DB advisory locks; the complete guard/state check then runs inside that lock before mutation. This prevents two requests from both validating the same old state and serializing only their writes.

`views/js/checkout-mutation-client.js` adds client-side latest-intent-wins protection without replacing server validation:

- a newer mutation increments a monotonically increasing sequence;
- the prior request is aborted through `AbortController` where available;
- every response is discarded when its sequence is no longer latest;
- `stale_state` may advance to the server-provided current version and replay the same latest intent exactly once;
- other retryable errors are not automatically replayed;
- the complete returned section set is validated before any DOM replacement.

Address delivery/invoice/same-address controls are sent as one atomic intent to avoid separate address requests racing each other. Missing separate-invoice selection is intentionally left for the server parser so validation remains translated and server-authoritative.

Virtual carts omit `delivery` from context-aware required refreshes because no delivery section exists in their shell DOM. This avoids representing an empty/nonexistent delivery fragment as a successful replaceable section. A future cart mutation that can change physical/virtual topology requires an explicit insert/remove DOM contract before exposure.

Real browser E2E must still prove these controls against rapid interaction and representative payment/carrier-module reinitialization before release.

### Server-side selection persistence

Validated payment/agreement selections are persisted in `jzopc_checkout_selection`; browser values never become `CheckoutServerSelections` directly. `CheckoutMutationOrchestrator` loads current selections only after acquiring the cart mutex, uses them for stale-state validation, and saves new selections only after a successful mutation returns every dependency-required section.

The store is scoped by the loaded cart's `(id_shop, id_cart)` and records `id_customer` as an additional binding. A customer mismatch deletes the stale row and returns empty selections. It never loads a cart by a submitted browser ID.

Only canonical payment option state, normalized agreement identifiers and an update timestamp are stored. No monetary values, payment credentials, payment form data, CSRF/session/auth tokens or customer/address PII are persisted there. Failed, stale, CSRF-rejected and incomplete mutations do not overwrite the row.

### Authoritative selection rendering

Payment/agreement section refreshes can receive `CheckoutServerSelections` only from the server-side mutation flow. Payment radios are checked only when the fresh Core-presented module/option matches the canonical persisted `module:option` key. Agreement checkboxes are checked only for canonical persisted agreement keys. Browser-submitted checked state is never copied directly into returned HTML.

After a real address change, the next authoritative selections are empty. The newly rendered payment/agreement sections therefore cannot silently restore approvals that were validated under the previous address state.

The browser client accepts server section HTML only after verifying that each response key maps to an existing section and the returned fragment contains exactly one matching `data-jzopc-section` root. This is a consistency guard, not an HTML sanitizer.

### Monetary tampering

`PrestaShopCheckoutStateFactory` has no browser monetary inputs. Cart/totals fingerprints come from Core cart/address checksums and `Cart::getOrderTotal()` calculations.

### Payment tampering

`CheckoutPaymentSelectionParser` accepts only bounded payment option/module identifiers. `CheckoutPaymentSelectionService` regenerates the current Core-backed payment options and requires exact module key, option ID and presented module-name agreement before returning a canonical server selection.

`CheckoutPaymentSelectionMutation` performs parsing and fresh Core validation inside the cart-mutex/stale-state critical section. A successful payment change also revalidates previously approved agreements against current required conditions; obsolete approval is cleared instead of silently carried forward.

A validated payment selection is not final-submit authorization. Final submission must regenerate eligibility and follow the payment module's native form/redirect/binary flow; the module must not call `PaymentModule::validateOrder()` as a shortcut around payment-module contracts.

### Legal-agreement tampering

`PrestaShopCheckoutAgreementsPresenter` discovers required conditions through Core `ConditionsToApproveFinder`, preserving shop terms and `termsAndConditions` module contributions. `CheckoutAgreementSelectionParser` accepts only bounded safe identifiers. `CheckoutAgreementSelectionService` regenerates the fresh Core set and succeeds only when the submitted set equals every currently required identifier exactly. Missing and forged keys fail closed.

`CheckoutAgreementSelectionMutation` performs parser + exact-set validation inside the guarded orchestrator critical section. Agreement validation must run again during final submission immediately before payment/order handoff.

### Rendering / XSS boundaries

Module-owned address, delivery, payment, agreement identifiers and summary strings are escaped according to HTML context. Raw HTML is intentionally limited to PrestaShop-defined Core/module markup boundaries:

- carrier `displayCarrierExtraContent`, `displayBeforeCarrier`, `displayAfterCarrier`;
- payment `displayPaymentTop`, `PaymentOption::additionalInformation` and module forms;
- legal-condition HTML returned by `ConditionsToApproveFinder::getConditionsToApproveForTemplate()`;
- section HTML already produced by these trusted server renderers when composed into the module checkout shell.

None of these raw boundaries may be populated from browser request data.

### SQL / injection

Direct SQL is limited to narrow infrastructure boundaries:

- advisory-lock acquisition/release through Doctrine DBAL with positional parameters (`GET_LOCK(?, ?)` / `RELEASE_LOCK(?)`);
- checkout-selection runtime reads/writes/deletes through Doctrine DBAL with all row values parameterized;
- install/upgrade/uninstall DDL generated only from the PrestaShop database prefix and engine after strict identifier validation.

Future direct SQL must remain parameterized where values are involved and justified by correctness/performance.

## Threat status

| Threat | Current status | Release requirement |
| --- | --- | --- |
| CSRF | Shared guard implemented; address/payment/agreement endpoints use it | Every future mutation/final endpoint must use an equivalent guarded boundary |
| Cross-cart/cart takeover | Cart binding implemented and used by concrete mutations | Never load submitted cart IDs in handlers |
| Customer mismatch | Generic guard + selection-store binding implemented | Add resource ownership checks per future mutable resource |
| Address IDOR | Saved-address endpoint uses strict parser + `customerHasAddress` inside orchestrator | Address add/edit/delete must use Core persisters and equivalent ownership controls |
| Address/cart delivery consistency | Saved-address delivery changes use Core `CheckoutSession::setIdAddressDelivery()` | Execute deferred real module-front/runtime address contracts after quota reset |
| Forged carrier | Core rendering only; mutation authorization not implemented | Validate selected delivery-option key against fresh server delivery options |
| Forged payment option | Fresh Core-backed validator, guarded mutation endpoint and server persistence implemented | Final submit must revalidate fresh eligibility immediately before handoff |
| Forged/missing agreement | Fresh exact-set validator, guarded mutation endpoint and persistence implemented | Final submit must revalidate fresh agreement set immediately before handoff |
| Stale browser state | Server state-version guard plus AbortController/sequence latest-wins client and bounded stale retry implemented | Prove live shell/browser behavior under rapid changes and payment reinitialization |
| Concurrent same-state writes | Per-cart mutex covers selection load/guard/write and address mutation | All future state-changing/final handlers must run inside mutex or stronger final-order boundary |
| Partial/malformed AJAX section apply | Client prevalidates complete returned section set before DOM writes | Exercise malformed/partial and out-of-order responses in browser E2E |
| Virtual-cart delivery refresh | Context-aware dependencies omit nonexistent delivery section | Future cart topology changes need explicit section insertion/removal semantics |
| Version-specific checkout takeover | 9.0/9.1 adapter and isolated 9.2+ provider implemented behind readiness gate | Execute deferred runtime + browser gates before opening gate |
| Endpoint exposure before checkout takeover | Shared activation gate remains closed; assets are OrderController-only and gated | Keep readiness false until integration gates pass |
| XSS | Normal values escaped; raw Core/module HTML isolated | Do not widen trusted HTML boundaries to browser/customer input |
| SQL/injection | Runtime DML parameterized; DDL identifiers validated | Parameterize and justify any future direct SQL |
| Duplicate order submission | **Not implemented** | Final-submit idempotency/order guard is a release blocker |
| Payment/order tampering | Selection mutation/persistence implemented; final handoff absent | Revalidate complete fresh checkout state and preserve native payment-module order flow |
| Persisted stale selection rows | Customer mismatch invalidation + address-change invalidation implemented | Successful-order deletion and bounded abandoned-cart cleanup required before release |

## Logging rules

Server logs may include operation name, shop ID, cart ID and non-sensitive error codes. Do not log passwords, payment credentials/secrets, CSRF/auth tokens, cookie/session identifiers, full customer payloads or unnecessary address/PII fields.

The browser client emits lifecycle events but does not log tokens, endpoint bootstrap data, customer payloads or payment form data.

## Verification status

Tests and runtime contracts continue to be created as normal. The installed Smarty shell contract, module-front Core `CheckoutSession` contract and the new address-related smoke/contracts have **not** been executed in this milestone because the repository's GitHub Actions free quota is exhausted. They must be run after quota reset and no unexecuted check is considered passing.

Previously completed installed-runtime checks before quota exhaustion covered PrestaShop 9.1.5 and 9.2.0-beta.1 module installation, capability/hook behavior, native OPC conflict detection and Core process/session adapter construction.

## Release-blocking security work

The module is intentionally not production-ready until deferred runtime/browser tests are executed, a fresh-Core carrier mutation is implemented, guest/customer and address add/edit flows use safe Core validation/persisters, final checkout validation rechecks addresses/carrier/payment/agreements/totals, duplicate/replayed final submission cannot create two orders, and persisted selection rows are cleaned up as part of checkout/order lifecycle.

Full runtime tests with representative payment/carrier modules, real front-controller/provider routing, Smarty/theme rendering and real database install/upgrade paths remain required.

See ADR-0007, ADR-0008, ADR-0009 and ADR-0013 for browser transport, trusted bootstrap, version-specific integration and saved-address mutation decisions.

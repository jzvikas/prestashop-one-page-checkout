# Security review

This document tracks checkout-specific threats, implemented controls and release-blocking gaps. It must be updated as checkout integration and final submission are added.

## Trust boundary

The browser is untrusted. The loaded PrestaShop `Context`/`Cart` is the checkout identity boundary. A submitted cart ID is only a binding assertion and is never used to load another cart. Prices, taxes, discounts, shipping price, payable total, carrier/payment eligibility, selected server checkout state and required legal conditions are server-authoritative.

## Implemented controls

### Transport and activation gates

Checkout mutations use a final shared module-front-controller gate. Requests must be `POST`; non-POST requests receive HTTP 405 with `Allow: POST`. Before a concrete mutation service can execute, the controller requires `JzOnePageCheckout::isCustomCheckoutActive()` to pass the same capability/native-conflict/configuration/integration-readiness policy used by checkout hooks. Inactive or incomplete custom checkout returns `checkout_unavailable` and performs no mutation.

The concrete `addressselection`, `addresssave`, `carrierselection`, `paymentselection` and `agreements` controllers contain no resource authorization or checkout business rules. They collect request values, resolve narrowly exposed application services and delegate to the guarded orchestrator path.

The trusted shell/bootstrap and both version-specific checkout process adapters exist, but `INTEGRATION_SHELL_READY` deliberately remains `false`. Consequently the 9.0/9.1 process adapter, 9.2+ provider, frontend asset hook and mutation endpoints all remain unreachable in normal checkout traffic until the remaining integration gates are proven.

PrestaShop 9.2-only provider code is isolated in a dedicated autoload path. Generic module code checks for the provider interface before resolving the provider class, preventing older 9.x runtimes from loading an unavailable interface. The 9.0/9.1 adapter reuses the existing Core `CheckoutSession` from the hook-provided process rather than constructing a session from browser input.

### Trusted browser bootstrap

`CheckoutBrowserBootstrapFactory` derives initial browser state only from server-owned context: the loaded cart, `Tools::getToken(false)`, `PrestaShopCheckoutStateFactory`, persisted validated selections and PrestaShop-generated module links. The shell exposes only cart ID, CSRF token, state version and saved-address/address-form/carrier/payment/agreement mutation endpoint URLs.

The browser client is dormant unless that complete module-owned root is present. The bootstrap does not contain client-authoritative totals, customer/address payloads, passwords, payment credentials or payment form data. The CSRF token is intentionally present in same-origin page markup for mutation requests and must never be logged.

### CSRF and cross-cart/customer binding

`CheckoutMutationGuard` requires the PrestaShop front-office token (`token`, with Core/theme-compatible `static_token` fallback), validates it with constant-time comparison, requires submitted cart ID to match the already loaded cart and verifies the context customer when the cart is customer-bound.

Concrete address/carrier/payment/agreement mutation services execute only through `CheckoutMutationOrchestrator`; future identity/final endpoints must use the same or a stronger boundary.

The native Core address form has a second, distinct persister token. `CheckoutAddressFormService` never trusts the browser for that token: it injects a fresh `Tools::getToken(true, $context)` into `CustomerAddressForm`/`CustomerAddressPersister` server-side. In the browser transport, `token`, `cartId` and `stateVersion` are reserved binding names. Serialized native/theme/module form fields with those names are dropped both while building the address payload and again while constructing the mutation request, so a Core form hidden token cannot replace the outer OPC CSRF authority.

### Address ownership / IDOR and Core persistence

`CheckoutAddressSelectionParser` accepts only normalized positive delivery/invoice identifiers plus an explicit same-address boolean. Same-address mode rejects a browser-supplied invoice id instead of accepting ambiguous authority.

`CheckoutAddressSelectionService` authorizes every submitted target with Core `Customer::customerHasAddress(cart_customer_id, address_id)`. When same-address mode reuses an existing cart delivery address that was not supplied in the request, ownership is checked again before it can become the invoice target. A missing checkout customer fails closed.

Authorized saved-address changes are applied through Core `CheckoutSession::setIdAddressDelivery()` / `setIdAddressInvoice()`. Delivery changes therefore preserve Core `Cart::updateAddressId()` side effects for per-product/customization delivery associations rather than changing only the cart header id. The service re-reads invoice state after delivery mutation because Core may synchronize a previously linked invoice address itself.

Address add/edit uses Core `CustomerAddressForm`, `CustomerAddressFormatter` and `CustomerAddressPersister`. Before loading an edit target, `CheckoutAddressFormService` checks `Customer::customerHasAddress()` itself so foreign IDs fail inside the JSON boundary instead of reaching redirect-oriented Core controller behavior. Core owns country/state/required-field validation, address/module validation hooks and used-address historization. The service does not call `Address::save()` directly and does not write cart address header fields directly.

Successful address persistence verifies that the resulting address belongs to the cart-bound customer, then selects it through the Core checkout session. Address saves invalidate persisted payment/agreement selections before downstream sections are rendered because country/state/address changes can alter tax, shipping, payment eligibility and legal requirements. Merely opening/re-rendering the editor does not invalidate selections or persist an address.

Anonymous carts fail closed with `address_customer_required` until identity handling creates/binds a real Core guest/customer. This is intentional; the address endpoint does not synthesize a customer from browser input.

`PrestaShopCheckoutSessionProvider` never constructs a session from browser data. It uses the active server `Context`. On module front controllers it follows the Core version split: legacy `DeliveryOptionsFinder` remains the PrestaShop 9.0 path; the 9.1+ improved `DeliveryOptionsProvider` is referenced dynamically and only used when both class/feature-flag capability checks succeed.

See ADR-0013 and ADR-0015.

### Carrier selection authorization

`CheckoutCarrierSelectionParser` accepts only a bounded opaque delivery-option-key format. The browser does not submit authoritative shipping price, carrier label, carrier eligibility or payable total.

`CheckoutCarrierSelectionService` requires a loaded non-virtual cart, resolves the server-owned Core `CheckoutSession`, regenerates `getDeliveryOptions()` and accepts only an exact key present in that fresh set. A missing/forged/stale key fails closed. A real selection change is persisted through Core `CheckoutSession::setDeliveryOption()` rather than by writing cart delivery state directly; an already-selected key is idempotent.

`CheckoutCarrierSelectionMutation` runs this authorization inside the same mutex/stale-state critical section as other mutations. A real carrier change clears persisted payment/agreement authority before rendering delivery, payment, agreements and summary because carrier choice can change totals, payment eligibility and module-provided legal state. Virtual carts reject carrier mutation entirely.

The trusted browser client sends only the selected opaque Core key to the server-generated carrier endpoint and publishes `jzopc:carrier:selected`. It never derives authority from the radio value alone; server validation remains mandatory.

See ADR-0014.

### Stale state and same-cart races

Every guarded mutation requires the prior `stateVersion`. `CheckoutCartMutex` serializes the cart critical section through parameterized DB advisory locks; the complete guard/state check then runs inside that lock before mutation. This prevents two requests from both validating the same old state and serializing only their writes.

`views/js/checkout-mutation-client.js` adds client-side latest-intent-wins protection without replacing server validation:

- a newer mutation increments a monotonically increasing sequence;
- the prior request is aborted through `AbortController` where available;
- every response is discarded when its sequence is no longer latest;
- `stale_state` may advance to the server-provided current version and replay the same latest intent exactly once;
- other retryable errors are not automatically replayed;
- the complete returned section set is validated before any DOM replacement.

Saved-address delivery/invoice/same-address controls are sent as one atomic intent to avoid separate address requests racing each other. Address editor present/save/country-refresh requests and carrier selection use the same sequence/abort/state-version channel, so a slower old address/carrier/payment request cannot overwrite a newer checkout intent in the browser; the server mutex/stale guard remains authoritative.

Virtual carts omit `delivery` from context-aware required refreshes because no delivery section exists in their shell DOM. A future cart mutation that can change physical/virtual topology requires an explicit insert/remove DOM contract before exposure.

Real browser E2E must still prove these controls against rapid interaction, native address-form replacement and representative payment/carrier-module reinitialization before release.

### Server-side selection persistence

Validated payment/agreement selections are persisted in `jzopc_checkout_selection`; browser values never become `CheckoutServerSelections` directly. `CheckoutMutationOrchestrator` loads current selections only after acquiring the cart mutex, uses them for stale-state validation, and saves new selections only after a successful mutation returns every dependency-required section.

The store is scoped by the loaded cart's `(id_shop, id_cart)` and records `id_customer` as an additional binding. A customer mismatch deletes the stale row and returns empty selections. It never loads a cart by a submitted browser ID.

Only canonical payment option state, normalized agreement identifiers and an update timestamp are stored. No monetary values, payment credentials, payment form data, CSRF/session/auth tokens or customer/address PII are persisted there. Failed, stale, CSRF-rejected and incomplete mutations do not overwrite the row.

### Authoritative selection rendering

Payment/agreement section refreshes can receive `CheckoutServerSelections` only from the server-side mutation flow. Payment radios are checked only when the fresh Core-presented module/option matches the canonical persisted `module:option` key. Agreement checkboxes are checked only for canonical persisted agreement keys. Browser-submitted checked state is never copied directly into returned HTML.

After a successful address persistence or real carrier change, the next authoritative selections are empty. The newly rendered payment/agreement sections therefore cannot silently restore approvals that were validated under the previous business state.

The browser client accepts server section HTML only after verifying that each response key maps to an existing section and the returned fragment contains exactly one matching `data-jzopc-section` root. This is a consistency guard, not an HTML sanitizer.

### Monetary tampering

`PrestaShopCheckoutStateFactory` has no browser monetary inputs. Cart/totals fingerprints come from Core cart/address checksums and `Cart::getOrderTotal()` calculations. Address and carrier mutations accept no browser monetary authority.

### Payment tampering

`CheckoutPaymentSelectionParser` accepts only bounded payment option/module identifiers. `CheckoutPaymentSelectionService` regenerates the current Core-backed payment options and requires exact module key, option ID and presented module-name agreement before returning a canonical server selection.

`CheckoutPaymentSelectionMutation` performs parsing and fresh Core validation inside the cart-mutex/stale-state critical section. A successful payment change also revalidates previously approved agreements against current required conditions; obsolete approval is cleared instead of silently carried forward.

A validated payment selection is not final-submit authorization. Final submission must regenerate eligibility and follow the payment module's native form/redirect/binary flow; the module must not call `PaymentModule::validateOrder()` as a shortcut around payment-module contracts.

### Legal-agreement tampering

`PrestaShopCheckoutAgreementsPresenter` discovers required conditions through Core `ConditionsToApproveFinder`, preserving shop terms and `termsAndConditions` module contributions. `CheckoutAgreementSelectionParser` accepts only bounded safe identifiers. `CheckoutAgreementSelectionService` regenerates the fresh Core set and succeeds only when the submitted set equals every currently required identifier exactly. Missing and forged keys fail closed.

`CheckoutAgreementSelectionMutation` performs parser + exact-set validation inside the guarded orchestrator critical section. Agreement validation must run again during final submission immediately before payment/order handoff.

### Rendering / XSS boundaries

Module-owned address, delivery, payment, agreement identifiers and summary strings are escaped according to HTML context. Raw HTML is intentionally limited to PrestaShop-defined Core/theme/module markup boundaries:

- the active theme's native `CustomerAddressForm::render()` output, including Core/module-added address fields;
- carrier `displayCarrierExtraContent`, `displayBeforeCarrier`, `displayAfterCarrier`;
- payment `displayPaymentTop`, `PaymentOption::additionalInformation` and module forms;
- legal-condition HTML returned by `ConditionsToApproveFinder::getConditionsToApproveForTemplate()`;
- section HTML already produced by these trusted server renderers when composed into the module checkout shell.

The native address form receives values only through Core form fields and validation; browser request strings are not directly concatenated into a raw HTML fragment by module code. None of these raw boundaries may be widened to arbitrary browser-controlled or customer-stored HTML.

### SQL / injection

Direct SQL is limited to narrow infrastructure boundaries:

- advisory-lock acquisition/release through Doctrine DBAL with positional parameters (`GET_LOCK(?, ?)` / `RELEASE_LOCK(?)`);
- checkout-selection runtime reads/writes/deletes through Doctrine DBAL with all row values parameterized;
- install/upgrade/uninstall DDL generated only from the PrestaShop database prefix and engine after strict identifier validation.

Future direct SQL must remain parameterized where values are involved and justified by correctness/performance.

## Threat status

| Threat | Current status | Release requirement |
| --- | --- | --- |
| CSRF | Shared guard implemented; address form has a separately server-generated Core persister token; trusted bindings are reserved in browser serialization | Every future identity/final endpoint must use an equivalent guarded boundary |
| Cross-cart/cart takeover | Cart binding implemented and used by concrete mutations | Never load submitted cart IDs in handlers |
| Customer mismatch | Generic guard + selection-store binding implemented | Add resource ownership checks per future mutable resource |
| Address IDOR | Saved-address selection and address edit use `customerHasAddress`; native persistence uses Core persister | Browser/runtime matrix must prove foreign edit rejection and guest/customer transitions |
| Address validation/persistence | `CustomerAddressForm` + formatter + persister implemented; successful save applies through `CheckoutSession` | Execute deferred real installed/browser add/edit/country/state/validation contracts after quota reset |
| Address/cart delivery consistency | Saved/new delivery addresses are applied through Core `CheckoutSession::setIdAddressDelivery()` | Execute deferred real module-front/runtime address contracts after quota reset |
| Forged carrier | Fresh Core-option validator, guarded endpoint and stale-safe browser transport implemented | Final submit must revalidate carrier eligibility; representative/no-carrier runtime matrix must pass |
| Forged payment option | Fresh Core-backed validator, guarded mutation endpoint and server persistence implemented | Final submit must revalidate fresh eligibility immediately before handoff |
| Forged/missing agreement | Fresh exact-set validator, guarded mutation endpoint and persistence implemented | Final submit must revalidate fresh agreement set immediately before handoff |
| Stale browser state | Server state-version guard plus AbortController/sequence latest-wins client and bounded stale retry implemented | Prove live shell/browser behavior under rapid changes and module reinitialization |
| Concurrent same-state writes | Per-cart mutex covers selection load/guard/write plus address/carrier mutation | All future state-changing/final handlers must run inside mutex or stronger final-order boundary |
| Partial/malformed AJAX section apply | Client prevalidates complete returned section set before DOM writes | Exercise malformed/partial and out-of-order responses in browser E2E |
| Virtual-cart delivery refresh | Context-aware dependencies omit nonexistent delivery section; carrier mutation rejects virtual carts | Future cart topology changes need explicit section insertion/removal semantics |
| Version-specific checkout takeover | 9.0/9.1 adapter and isolated 9.2+ provider implemented behind readiness gate | Execute deferred runtime + browser gates before opening gate |
| Endpoint exposure before checkout takeover | Shared activation gate remains closed; assets are OrderController-only and gated | Keep readiness false until integration gates pass |
| XSS | Normal values escaped; raw Core/theme/module HTML isolated, including native address form | Do not widen trusted HTML boundaries to arbitrary browser/customer input |
| SQL/injection | Runtime DML parameterized; DDL identifiers validated | Parameterize and justify any future direct SQL |
| Guest/account identity | **Not implemented** | Core customer form/persister/login flow is a release blocker before anonymous checkout can use address persistence |
| Duplicate order submission | **Not implemented** | Final-submit idempotency/order guard is a release blocker |
| Payment/order tampering | Selection mutation/persistence implemented; final handoff absent | Revalidate complete fresh checkout state and preserve native payment-module order flow |
| Persisted stale selection rows | Customer mismatch plus address/carrier-change invalidation implemented | Successful-order deletion and bounded abandoned-cart cleanup required before release |

## Logging rules

Server logs may include operation name, shop ID, cart ID and non-sensitive error codes. Do not log passwords, payment credentials/secrets, CSRF/auth tokens, cookie/session identifiers, full customer payloads or unnecessary address/PII fields.

The browser client emits lifecycle events but does not log tokens, endpoint bootstrap data, customer/address form payloads or payment form data.

## Verification status

Tests and runtime contracts continue to be created as normal. The address-form source/Smarty/bootstrap/browser contracts in this milestone have **not** been executed because the repository's GitHub Actions free quota remains exhausted. The execution container also could not clone GitHub, so no local PHP/Node suite was available. They must run after quota reset and no unexecuted check is considered passing.

For the preceding carrier milestone, PHP 8.4 syntax checks passed for the new carrier/controller/integration PHP files, `CheckoutCarrierSelectionSmokeTest` passed in a local isolated repository-compatible harness, focused browser-bootstrap and mutation-client contracts passed, and Node.js 22 `node --check` passed for that version of the mutation client. Those earlier focused checks do not validate this address-form delta and do not replace the repository-wide or installed-runtime matrices.

Previously completed installed-runtime checks before quota exhaustion covered PrestaShop 9.1.5 and 9.2.0-beta.1 module installation, capability/hook behavior, native OPC conflict detection and Core process/session adapter construction.

## Release-blocking security work

The module is intentionally not production-ready until deferred runtime/browser tests are executed, guest/customer identity creation/login/update uses safe Core validation/persisters, final checkout validation rechecks addresses/carrier/payment/agreements/totals, duplicate/replayed final submission cannot create two orders, persisted selection rows are cleaned up as part of checkout/order lifecycle, and representative carrier/payment integrations pass the controlled browser/runtime matrix.

Full runtime tests with real front-controller/provider routing, active-theme native address-form rendering, Smarty/theme rendering and real database install/upgrade paths remain required.

See ADR-0007, ADR-0008, ADR-0009, ADR-0013, ADR-0014 and ADR-0015 for browser transport, trusted bootstrap, version-specific integration, saved-address, carrier-mutation and native address-form decisions.

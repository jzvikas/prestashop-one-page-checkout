# Security review

This document tracks checkout-specific threats, implemented controls and release-blocking gaps. It must be updated as checkout integration and final submission are added.

## Trust boundary

The browser is untrusted. The loaded PrestaShop `Context`/`Cart` is the checkout identity boundary. A submitted cart ID is only a binding assertion and is never used to load another cart. Prices, taxes, discounts, shipping price, payable total, payment eligibility, selected server checkout state and required legal conditions are server-authoritative.

## Implemented controls

### Transport and activation gates

Checkout mutations use a final shared module-front-controller gate. Requests must be `POST`; non-POST requests receive HTTP 405 with `Allow: POST`. Before a concrete mutation service can execute, the controller also requires `JzOnePageCheckout::isCustomCheckoutActive()` to pass the same capability/native-conflict/configuration/integration-readiness policy used by the checkout hooks. Inactive or incomplete custom checkout returns a stable `checkout_unavailable` response and performs no mutation.

The concrete `paymentselection` and `agreements` controllers contain no resource authorization or checkout business rules. They only collect request values, resolve narrowly exposed application services and delegate to the guarded orchestrator path.

The browser mutation client does not weaken that gate. It is dormant unless a future active checkout shell provides a complete module-owned bootstrap root with cart ID, CSRF token, state version and endpoint URLs. Adding the client alone does not make mutation routes usable while integration readiness is false.

### CSRF and cross-cart/customer binding

`CheckoutMutationGuard` requires the PrestaShop front-office token (`token`, with Core/theme-compatible `static_token` fallback), validates it with constant-time comparison, requires submitted cart ID to match the already loaded cart and verifies the context customer when the cart is customer-bound.

Concrete payment/agreement mutation services execute only through `CheckoutMutationOrchestrator`; future mutation/final endpoints must use the same or a stronger boundary.

### Address ownership / IDOR

`CheckoutAddressSelectionService` authorizes delivery/invoice addresses with Core `Customer::customerHasAddress(cart_customer_id, address_id)`. Same-address mode does not trust a client invoice ID and mirrors an authorized current delivery address server-side. Malformed/non-positive identifiers are rejected.

### Stale state and same-cart races

Every guarded mutation requires the prior `stateVersion`. `CheckoutCartMutex` serializes the cart critical section through parameterized DB advisory locks; the complete guard/state check then runs inside that lock before mutation. This prevents two requests from both validating the same old state and serializing only their writes.

`views/js/checkout-mutation-client.js` adds client-side latest-intent-wins protection without replacing server validation:

- a newer mutation increments a monotonically increasing sequence;
- the prior request is aborted through `AbortController` where available;
- every response is discarded when its sequence is no longer latest, so correctness does not depend only on successful cancellation;
- a `stale_state` response may advance to the server-provided current version and replay the same latest intent exactly once;
- other retryable errors are not automatically replayed;
- the complete returned section set is validated before any DOM replacement, preventing a malformed/partial response from causing a partially applied checkout state.

The real checkout shell/browser E2E must still prove these controls against rapid interaction and representative payment-module reinitialization before release.

### Server-side selection persistence

Validated payment/agreement selections are persisted in `jzopc_checkout_selection`; browser values never become `CheckoutServerSelections` directly. `CheckoutMutationOrchestrator` loads current selections only after acquiring the cart mutex, uses those selections for stale-state validation, and saves new selections only after a successful mutation returns every dependency-required section.

The store is scoped by the loaded cart's `(id_shop, id_cart)` and records `id_customer` as an additional binding. A customer mismatch deletes the stale row and returns empty selections rather than transferring payment/agreement state to another owner. The store never loads a cart by a submitted browser ID.

Only canonical payment option state, normalized agreement identifiers and an update timestamp are stored. No monetary values, payment credentials, payment form data, CSRF/session/auth tokens or customer/address PII are persisted there. Failed, stale, CSRF-rejected and incomplete mutations do not overwrite the row.

### Authoritative selection rendering

Payment/agreement section refreshes can receive `CheckoutServerSelections` only from the server-side mutation flow. Payment radios are checked only when the fresh Core-presented module/option matches the canonical persisted `module:option` key. Agreement checkboxes are checked only for canonical persisted agreement keys. Browser-submitted checked state is never copied directly into returned HTML.

The browser client accepts server section HTML only after verifying that each response key maps to an existing section and the returned fragment contains exactly one matching `data-jzopc-section` root. This is a consistency guard, not an HTML sanitizer: trusted raw HTML boundaries remain controlled by server renderers as described below.

### Monetary tampering

`PrestaShopCheckoutStateFactory` has no browser monetary inputs. Cart/totals fingerprints come from Core cart/address checksums and `Cart::getOrderTotal()` calculations.

### Payment tampering

`CheckoutPaymentSelectionParser` accepts only bounded payment option/module identifiers. `CheckoutPaymentSelectionService` regenerates the current Core-backed payment options and requires exact module key, option ID and presented module-name agreement before returning a canonical server selection.

`CheckoutPaymentSelectionMutation` performs parsing and fresh Core validation inside the cart-mutex/stale-state critical section. A successful payment change also revalidates previously approved agreements against current required conditions; obsolete approval is cleared instead of silently carried forward.

A validated payment selection is not final-submit authorization. Final submission must regenerate eligibility and follow the payment module's native form/redirect/binary flow; the module must not call `PaymentModule::validateOrder()` as a shortcut around payment-module contracts.

### Legal-agreement tampering

`PrestaShopCheckoutAgreementsPresenter` discovers required conditions through Core `ConditionsToApproveFinder`, preserving shop terms and `termsAndConditions` module contributions. `CheckoutAgreementSelectionParser` accepts only bounded safe identifiers. `CheckoutAgreementSelectionService` regenerates the fresh Core set and succeeds only when the submitted set equals every currently required identifier exactly. Missing and forged keys fail closed.

`CheckoutAgreementSelectionMutation` performs parser + exact-set validation inside the guarded orchestrator critical section before any new approval set can be persisted. A validation failure may return refreshed server-authoritative agreement markup without replacing prior stored selection state.

Agreement validation must run again during final submission immediately before payment/order handoff because required conditions may change after browser selection.

### Rendering / XSS boundaries

Module-owned address, delivery, payment, agreement identifiers and summary strings are escaped according to HTML context. Raw HTML is intentionally limited to PrestaShop-defined Core/module markup boundaries:

- carrier `displayCarrierExtraContent`, `displayBeforeCarrier`, `displayAfterCarrier`;
- payment `displayPaymentTop`, `PaymentOption::additionalInformation` and module forms;
- legal-condition HTML returned by `ConditionsToApproveFinder::getConditionsToApproveForTemplate()`.

None of these raw boundaries may be populated from browser request data. Future renderers must keep raw Core/module HTML explicit and narrowly scoped.

### SQL / injection

Direct SQL is limited to narrow infrastructure boundaries:

- advisory-lock acquisition/release through Doctrine DBAL with positional parameters (`GET_LOCK(?, ?)` / `RELEASE_LOCK(?)`);
- checkout-selection runtime reads/writes/deletes through Doctrine DBAL with all row values parameterized;
- install/upgrade/uninstall DDL generated only from the PrestaShop database prefix and engine after strict identifier validation.

Future direct SQL must remain parameterized where values are involved and justified by correctness/performance.

## Threat status

| Threat | Current status | Release requirement |
| --- | --- | --- |
| CSRF | Shared guard implemented; payment/agreement endpoints use it | Every future mutation/final endpoint must use an equivalent guarded boundary |
| Cross-cart/cart takeover | Cart binding implemented and used by payment/agreement mutations | Never load submitted cart IDs in handlers |
| Customer mismatch | Generic guard + selection-store binding implemented | Add resource ownership checks per future mutable resource |
| Address IDOR | Parser/ownership service implemented | Concrete address endpoint must use orchestrator + service |
| Forged carrier | Core rendering only; mutation authorization not implemented | Validate selected delivery-option key against fresh server delivery options |
| Forged payment option | Fresh Core-backed validator, guarded mutation endpoint and server persistence implemented | Final submit must revalidate fresh eligibility immediately before handoff |
| Forged/missing agreement | Fresh exact-set validator, guarded mutation endpoint and persistence implemented | Final submit must revalidate fresh agreement set immediately before handoff |
| Stale browser state | Server state-version guard plus AbortController/sequence latest-wins client and bounded stale retry implemented | Prove live shell/browser behavior under rapid changes and payment reinitialization |
| Concurrent same-state writes | Per-cart mutex covers selection load/guard/write | All future state-changing/final handlers must run inside mutex or stronger final-order boundary |
| Partial/malformed AJAX section apply | Client prevalidates the complete returned section set before DOM writes | Exercise malformed/partial and out-of-order responses in browser E2E |
| XSS | Normal values escaped; raw Core/module HTML isolated | Do not widen trusted HTML boundaries to browser/customer input |
| SQL/injection | Runtime DML parameterized; DDL identifiers validated | Parameterize and justify any future direct SQL |
| Endpoint exposure before checkout takeover | POST + activation gate implemented; mutation client is dormant without trusted root bootstrap | Keep integration-readiness closed until version-specific checkout shell/client is tested |
| Duplicate order submission | **Not implemented** | Final-submit idempotency/order guard is a release blocker |
| Payment/order tampering | Selection mutation/persistence implemented; final handoff absent | Revalidate complete fresh checkout state and preserve native payment-module order flow |
| Persisted stale selection rows | Customer mismatch invalidation implemented | Successful-order deletion and bounded abandoned-cart cleanup required before release |

## Logging rules

Server logs may include operation name, shop ID, cart ID and non-sensitive error codes. Do not log passwords, payment credentials/secrets, CSRF/auth tokens, cookie/session identifiers, full customer payloads or unnecessary address/PII fields.

The browser client emits lifecycle events but does not log tokens, endpoint bootstrap data, customer payloads or payment form data.

## Release-blocking security work

The module is intentionally not production-ready until version-specific checkout integration proves the mutation client under real browser races without bypassing the activation gate, carrier/address mutations use fresh Core authorization, final checkout validation rechecks addresses/carrier/payment/agreements/totals, duplicate/replayed final submission cannot create two orders, and persisted selection rows are cleaned up as part of checkout/order lifecycle.

Full runtime tests with representative payment/carrier modules, real front-controller routing and real database install/upgrade paths are also required.

See `ADR-0007-stale-safe-browser-mutation-transport.md` for the browser race/response-application decision.

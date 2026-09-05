# Security review

This document tracks checkout-specific threats, implemented controls and release-blocking gaps. It must be updated as mutation endpoints and final submission are added.

## Trust boundary

The browser is untrusted. The loaded PrestaShop `Context`/`Cart` is the checkout identity boundary. A submitted cart ID is only a binding assertion and is never used to load another cart. Prices, taxes, discounts, shipping price, payable total, payment eligibility, selected server checkout state and required legal conditions are server-authoritative.

## Implemented controls

### CSRF and cross-cart/customer binding

`CheckoutMutationGuard` requires the PrestaShop front-office token (`token`, with Core/theme-compatible `static_token` fallback), validates it with constant-time comparison, requires the submitted cart ID to match the already loaded cart and verifies the context customer when the cart is customer-bound.

### Address ownership / IDOR

`CheckoutAddressSelectionService` authorizes delivery/invoice addresses with Core `Customer::customerHasAddress(cart_customer_id, address_id)`. Same-address mode does not trust a client invoice ID and mirrors an authorized current delivery address server-side. Malformed/non-positive identifiers are rejected.

### Stale state and same-cart races

Every guarded mutation requires the prior `stateVersion`. `CheckoutCartMutex` first serializes the cart critical section through parameterized DB advisory locks; the complete guard/state check then runs inside that lock before the mutation. This prevents two requests from both validating the same old state and serializing only their writes.

### Server-side selection persistence

Validated payment/agreement selections are persisted in `jzopc_checkout_selection`; browser values never become `CheckoutServerSelections` directly. `CheckoutMutationOrchestrator` loads current selections only after acquiring the cart mutex, uses those selections for stale-state validation, and saves new selections only after a successful mutation returns every dependency-required section.

The store is scoped by the loaded cart's `(id_shop, id_cart)` and records `id_customer` as an additional binding. A customer mismatch deletes the stale row and returns empty selections rather than transferring payment/agreement state to another owner. The store never loads a cart by a submitted browser ID.

Only canonical payment option state, normalized agreement identifiers and an update timestamp are stored. No monetary values, payment credentials, payment form data, CSRF/session/auth tokens or customer/address PII are persisted there. Failed, stale, CSRF-rejected and incomplete mutations do not overwrite the row.

### Monetary tampering

`PrestaShopCheckoutStateFactory` has no browser monetary inputs. Cart/totals fingerprints come from Core cart/address checksums and `Cart::getOrderTotal()` calculations.

### Payment tampering

`CheckoutPaymentSelectionParser` accepts only bounded payment option/module identifiers. `CheckoutPaymentSelectionService` does not trust that pair: it regenerates the current Core-backed payment options and requires exact module key, option ID and presented module-name agreement before returning a canonical server selection.

A validated payment selection is not final-submit authorization. Final submission must regenerate eligibility again and follow the payment module's native form/redirect/binary flow; the module must not call `PaymentModule::validateOrder()` as a shortcut around payment-module contracts.

### Legal-agreement tampering

`PrestaShopCheckoutAgreementsPresenter` discovers the required legal set through Core `ConditionsToApproveFinder`, preserving shop terms and `termsAndConditions` module contributions. `CheckoutAgreementSelectionParser` accepts only bounded safe identifiers. `CheckoutAgreementSelectionService` regenerates the fresh Core set and succeeds only when the submitted set equals every currently required identifier exactly. Missing and forged keys fail closed.

Agreement validation must run again during final submission immediately before payment/order handoff because required conditions may change after an earlier browser selection.

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
| CSRF | Shared guard implemented | Every mutation endpoint must use the guarded orchestrator path |
| Cross-cart/cart takeover | Cart binding implemented | Never load submitted cart IDs in handlers |
| Customer mismatch | Generic guard + selection-store binding implemented | Add resource ownership checks per mutable resource |
| Address IDOR | Parser/ownership service implemented | Concrete address endpoint must use orchestrator + service |
| Forged carrier | Core rendering only; mutation authorization not implemented | Validate selected delivery-option key against fresh server delivery options |
| Forged payment option | Fresh Core-backed selection validator + server persistence implemented | Public mutation and final submit must invoke fresh validation inside orchestrator/final critical sections |
| Forged/missing agreement | Fresh exact-set validator + server persistence implemented | Public mutation/final submit must invoke fresh validation inside orchestrator/final critical sections |
| Stale browser state | State-version guard and persisted selection participation implemented | Controllers must return conflict/recovery and prevent stale writes |
| Concurrent same-state writes | Per-cart mutex covers selection load/guard/write | All state-changing handlers must run inside the mutex |
| XSS | Normal values escaped; raw Core/module HTML isolated | Do not widen trusted HTML boundaries to browser/customer input |
| SQL/injection | Runtime DML parameterized; DDL identifiers validated | Parameterize and justify any future direct SQL |
| Duplicate order submission | **Not implemented** | Final-submit idempotency/order guard is a release blocker |
| Payment/order tampering | Selection validation/persistence implemented; final handoff absent | Revalidate complete fresh checkout state and preserve native payment-module order flow |
| Persisted stale selection rows | Customer mismatch invalidation implemented | Successful-order deletion and bounded abandoned-cart cleanup required before release |

## Logging rules

Server logs may include operation name, shop ID, cart ID and non-sensitive error codes. Do not log passwords, payment credentials/secrets, CSRF/auth tokens, cookie/session identifiers, full customer payloads or unnecessary address/PII fields.

## Release-blocking security work

The module is intentionally not production-ready until concrete mutation endpoints cannot bypass the guard/orchestrator, carrier selection receives fresh Core authorization, final checkout validation rechecks addresses/carrier/payment/agreements/totals, duplicate/replayed final submission cannot create two orders, and persisted selection rows are cleaned up as part of checkout/order lifecycle. Full runtime tests with representative payment/carrier modules and real database install/upgrade paths are also required.

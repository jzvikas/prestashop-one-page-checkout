# Security review

This document tracks checkout-specific threats, implemented controls and release-blocking gaps. It must be updated as mutation endpoints and final submission are added.

## Trust boundary

The browser is untrusted. The loaded PrestaShop `Context`/`Cart` is the checkout identity boundary. A submitted cart ID is only a binding assertion and is never used to load another cart. Prices, taxes, discounts, shipping price, payable total, payment eligibility and required legal conditions are server-authoritative.

## Implemented controls

### CSRF and cross-cart/customer binding

`CheckoutMutationGuard` requires the PrestaShop front-office token (`token`, with Core/theme-compatible `static_token` fallback), validates it with constant-time comparison, requires the submitted cart ID to match the already loaded cart and verifies the context customer when the cart is customer-bound.

### Address ownership / IDOR

`CheckoutAddressSelectionService` authorizes delivery/invoice addresses with Core `Customer::customerHasAddress(cart_customer_id, address_id)`. Same-address mode does not trust a client invoice ID and mirrors an authorized current delivery address server-side. Malformed/non-positive identifiers are rejected.

### Stale state and same-cart races

Every guarded mutation requires the prior `stateVersion`. `CheckoutCartMutex` first serializes the cart critical section through parameterized DB advisory locks; the complete guard/state check then runs inside that lock before the mutation. This prevents two requests from both validating the same old state and serializing only their writes.

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

The only direct SQL in the current architecture is advisory-lock acquisition/release through Doctrine DBAL using positional parameters (`GET_LOCK(?, ?)` / `RELEASE_LOCK(?)`). Future direct SQL must remain parameterized and justified.

## Threat status

| Threat | Current status | Release requirement |
| --- | --- | --- |
| CSRF | Shared guard implemented | Every mutation endpoint must use the guarded orchestrator path |
| Cross-cart/cart takeover | Cart binding implemented | Never load submitted cart IDs in handlers |
| Customer mismatch | Generic guard implemented | Add resource ownership checks per mutable resource |
| Address IDOR | Parser/ownership service implemented | Concrete address endpoint must use orchestrator + service |
| Forged carrier | Core rendering only; mutation authorization not implemented | Validate selected delivery-option key against fresh server delivery options |
| Forged payment option | Fresh Core-backed selection validator implemented | Public mutation and final submit must use it inside the cart mutex/stale-state critical section |
| Forged/missing agreement | Fresh exact-set Core-backed validator implemented | Public mutation/final submit must use it inside the guarded critical section |
| Stale browser state | State-version guard implemented | Controllers must return conflict/recovery and prevent stale writes |
| Concurrent same-state writes | Per-cart mutex implemented | All state-changing handlers must run inside the mutex |
| XSS | Normal values escaped; raw Core/module HTML isolated | Do not widen trusted HTML boundaries to browser/customer input |
| SQL/injection | Current direct SQL parameterized | Parameterize and justify any future direct SQL |
| Duplicate order submission | **Not implemented** | Final-submit idempotency/order guard is a release blocker |
| Payment/order tampering | Selection validation implemented; final handoff absent | Revalidate complete fresh checkout state and preserve native payment-module order flow |

## Logging rules

Server logs may include operation name, shop ID, cart ID and non-sensitive error codes. Do not log passwords, payment credentials/secrets, CSRF/auth tokens, cookie/session identifiers, full customer payloads or unnecessary address/PII fields.

## Release-blocking security work

The module is intentionally not production-ready until concrete mutation endpoints cannot bypass the guard/orchestrator, carrier selection receives fresh Core authorization, final checkout validation rechecks addresses/carrier/payment/agreements/totals, and duplicate/replayed final submission cannot create two orders. Full runtime tests with representative payment/carrier modules are also required.

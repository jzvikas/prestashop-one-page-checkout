# Security review

This document tracks checkout-specific threats, implemented controls and release-blocking gaps. It must be updated as mutation endpoints and final submission are added.

## Current trust boundary

The browser is untrusted. The current application layer treats the PrestaShop `Context`/loaded `Cart` as the server-side checkout identity and never loads a cart from a submitted cart identifier. Prices, taxes, discounts, shipping prices and payable totals are derived from PrestaShop Core only.

## Implemented controls

### CSRF

State-changing requests are required to pass the PrestaShop front-office token validated against `Tools::getToken(false)` with `hash_equals()`. The guard accepts the standard `token` key and the Core/theme-compatible `static_token` fallback.

### Cross-cart access / cart takeover

`CheckoutMutationGuard` requires a positive submitted `cartId` but uses it only as a binding assertion. It compares that value with the cart already loaded in the current PrestaShop context and never instantiates a cart from the submitted ID.

### Customer/cart binding

When the loaded cart already belongs to a customer, the current context customer ID must match the cart owner before a mutation may continue. Resource-specific authorization is still required for addresses and other customer-owned objects.

### Address ownership / IDOR

`CheckoutAddressSelectionService` authorizes every delivery/invoice address with Core `Customer::customerHasAddress(cart_customer_id, address_id)` before changing the cart. Same-address mode does not accept a client invoice ID; the server mirrors the already-authorized/current delivery address and rechecks its ownership. A cart without a checkout customer cannot select saved addresses.

The request parser rejects malformed/non-positive IDs and requires an explicit invoice address when separate invoice mode is selected. The selection service is designed to execute only behind the generic mutation guard/orchestrator; the future endpoint remains responsible for using that pipeline.

### Stale checkout state

Every guarded mutation requires the previous `stateVersion`. The guard rebuilds a fresh server-authoritative checkout state and blocks a stale version before the mutation handler is entered. A stale result contains the current server state so the transport layer can return a recoverable conflict response.

### Per-cart serialization

`CheckoutCartMutex` serializes mutation critical sections with the database server's connection-owned advisory lock. The lock name is scoped by a hash of database name/table prefix plus cart ID, so separate PrestaShop installations on one database server do not intentionally contend.

Lock acquisition and release use Doctrine DBAL positional parameters (`GET_LOCK(?, ?)` / `RELEASE_LOCK(?)`). A timeout or acquisition error fails closed: the mutation callback is not run. Release always occurs in `finally`; when `RELEASE_LOCK` fails or reports that the lock was not released, the DBAL connection is closed as a final connection-owned lock release attempt.

This mechanism works across PHP workers/web nodes that share the database server and does not require a custom lock table.

### Monetary tampering

The state factory has no browser monetary inputs. Cart/totals fingerprints are derived from PrestaShop Core checksums and `Cart::getOrderTotal()` results.

### Rendering / XSS boundary

Module-owned address, delivery, payment and summary markup escapes ordinary presented strings according to HTML context. The delivery template intentionally renders only Core/module hook HTML (`displayCarrierExtraContent`, `displayBeforeCarrier`, `displayAfterCarrier`) without escaping. The payment template similarly treats `displayPaymentTop`, `PaymentOption::additionalInformation` and module-provided payment forms as explicit trusted payment-module HTML boundaries because those fields are defined by PrestaShop as module HTML content.

Payment option IDs, module names, customer-visible call-to-action text, logo/action attributes and generated hidden input names/values are escaped by module-owned markup. None of the trusted raw-HTML boundaries may be populated from browser request data. Future renderers must keep raw hook/module HTML boundaries explicit and must not widen them to arbitrary stored customer input.

Payment rendering does not authorize a browser payment choice. `PrestaShopCheckoutPaymentOptionsPresenter` discovers the current server-valid options through Core `PaymentOptionsFinder::present()`, but no payment-selection endpoint exists yet. Any future payment mutation must compare the requested option identifier/module against a freshly generated Core option set inside the stale-state guard and cart mutex before persisting or acting on that choice.

## Threat status

| Threat | Current status | Release requirement |
| --- | --- | --- |
| CSRF | Guard implemented | Every mutation controller must use it |
| Cross-cart/cart takeover | Generic cart binding implemented | Never load submitted cart IDs in mutation handlers |
| Customer mismatch | Generic guard implemented | Add resource ownership checks per handler |
| Address IDOR | Selection/parser guard implemented | Concrete address endpoint must use orchestrator + selection service |
| Forged carrier | Rendering uses current Core options; mutation authorization not implemented yet | Validate the submitted delivery-option key against the freshly generated server delivery options before selection |
| Forged payment option | Rendering is Core-discovered; mutation authorization not implemented yet | Validate requested payment option/module against a fresh `PaymentOptionsFinder` result inside the guarded mutation critical section |
| Stale browser state | Guard implemented | Return conflict/recovery response and prevent stale mutation |
| Concurrent same-state writes | Per-cart mutex implemented | All mutation execution must occur inside the mutex |
| XSS | Module-owned rendering escapes normal values; carrier/payment module HTML is isolated to explicit trusted boundaries | Keep raw hook/module HTML isolated; never render browser-controlled HTML with `nofilter` |
| SQL/injection | Parameterized advisory-lock SQL only | Parameterize any future SQL; justify direct SQL |
| Duplicate order submission | Not implemented yet | Final-submit idempotency/order guard is a release blocker |
| Payment tampering | Server-side payment discovery implemented for rendering; selection/submission validation not implemented | Recompute server state, revalidate current payment eligibility and use PrestaShop payment option/order validation flow |

## Concurrency ordering rule

The stale-state check and the mutation must run inside the same per-cart critical section. Checking state before acquiring the mutex would reintroduce the race: two requests could both validate the same old state and then serialize only the writes. Mutation orchestration therefore acquires the cart mutex first, rebuilds/validates state second, mutates third, then rebuilds the response state before releasing the lock.

Read-only refreshes do not need this mutex unless they must provide a snapshot guaranteed not to overlap a mutation.

## Logging rules

Server logs may include operation name, shop ID, cart ID and non-sensitive error codes. Do not log passwords, payment credentials/secrets, CSRF/auth tokens, cookie/session identifiers, full customer payloads or unnecessary address/PII fields.

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

### Stale checkout state

Every guarded mutation requires the previous `stateVersion`. The guard rebuilds a fresh server-authoritative checkout state and blocks a stale version before the mutation handler is entered. A stale result contains the current server state so the transport layer can return a recoverable conflict response.

### Per-cart serialization

`CheckoutCartMutex` serializes mutation critical sections with the database server's connection-owned advisory lock. The lock name is scoped by a hash of database name/table prefix plus cart ID, so separate PrestaShop installations on one database server do not intentionally contend.

Lock acquisition and release use Doctrine DBAL positional parameters (`GET_LOCK(?, ?)` / `RELEASE_LOCK(?)`). A timeout or acquisition error fails closed: the mutation callback is not run. Release always occurs in `finally`; when `RELEASE_LOCK` fails or reports that the lock was not released, the DBAL connection is closed as a final connection-owned lock release attempt.

This mechanism works across PHP workers/web nodes that share the database server and does not require a custom lock table.

### Monetary tampering

The state factory has no browser monetary inputs. Cart/totals fingerprints are derived from PrestaShop Core checksums and `Cart::getOrderTotal()` results.

## Threat status

| Threat | Current status | Release requirement |
| --- | --- | --- |
| CSRF | Guard implemented | Every mutation controller must use it |
| Cross-cart/cart takeover | Generic cart binding implemented | Never load submitted cart IDs in mutation handlers |
| Customer mismatch | Generic guard implemented | Add resource ownership checks per handler |
| Address IDOR | Not implemented yet | Verify address belongs to current checkout customer before read/write/delete |
| Forged carrier | Not implemented yet | Validate against current server delivery options before selection |
| Forged payment option | Not implemented yet | Validate against current `PaymentOptionsFinder` output |
| Stale browser state | Guard implemented | Return conflict/recovery response and prevent stale mutation |
| Concurrent same-state writes | Per-cart mutex implemented | All mutation execution must occur inside the mutex |
| XSS | No custom checkout rendering yet | Context-aware escaping and no untrusted raw HTML outside trusted hook/module output |
| SQL/injection | Parameterized advisory-lock SQL only | Parameterize any future SQL; justify direct SQL |
| Duplicate order submission | Not implemented yet | Final-submit idempotency/order guard is a release blocker |
| Payment tampering | Not implemented yet | Recompute server state and use PrestaShop payment option/order validation flow |

## Concurrency ordering rule

The stale-state check and the mutation must run inside the same per-cart critical section. Checking state before acquiring the mutex would reintroduce the race: two requests could both validate the same old state and then serialize only the writes. Future mutation orchestration must therefore acquire the cart mutex first, rebuild/validate state second, mutate third, then rebuild the response state before releasing the lock.

Read-only refreshes do not need this mutex unless they must provide a snapshot guaranteed not to overlap a mutation.

## Logging rules

Server logs may include operation name, shop ID, cart ID and non-sensitive error codes. Do not log passwords, payment credentials/secrets, CSRF/auth tokens, cookie/session identifiers, full customer payloads or unnecessary address/PII fields.

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
| Concurrent same-state writes | Not fully solved yet | Add per-cart request serialization before mutation endpoints are production-enabled |
| XSS | No custom checkout rendering yet | Context-aware escaping and no untrusted raw HTML outside trusted hook/module output |
| SQL/injection | No module SQL in current state/guard path | Parameterize any future SQL; justify direct SQL |
| Duplicate order submission | Not implemented yet | Final-submit idempotency/order guard is a release blocker |
| Payment tampering | Not implemented yet | Recompute server state and use PrestaShop payment option/order validation flow |

## Important concurrency note

A state token prevents an already-stale request from entering a handler, but two concurrent mutations can both begin from the same valid state before either writes. Therefore stale-state checking alone is not sufficient serialization. Mutation endpoints must not be considered production-ready until a per-cart serialization strategy is implemented and tested.

The official PrestaShop `ps_onepagecheckout` module currently serializes AJAX work per cart with a database advisory lock. Our implementation will verify the safest cross-version mechanism before adopting a lock; correctness takes priority over silently proceeding through a known race window.

## Logging rules

Server logs may include operation name, shop ID, cart ID and non-sensitive error codes. Do not log passwords, payment credentials/secrets, CSRF/auth tokens, cookie/session identifiers, full customer payloads or unnecessary address/PII fields.

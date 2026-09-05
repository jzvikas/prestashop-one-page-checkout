# ADR-0022: Finalization reservation recovery safety

## Status

Accepted for source implementation. Installed-runtime/browser verification remains pending and `INTEGRATION_SHELL_READY=false` remains unchanged.

## Context

The finalization reservation is the cross-tab/process barrier between a successful OPC preflight and the native PrestaShop/payment-module handoff. The previous 90-second default was intentionally short, but it is too aggressive for slow redirects, payment initialization, 3-D Secure preparation, overloaded shops or a browser that stalls after the payment module has started work.

A second recovery concern exists around explicit browser release. Release is useful before native payment handoff has begun, but it must never remove the barrier after Core has already created an order. If Core order state cannot be read reliably, deleting the reservation is less safe than leaving it to expire.

The browser boundary is stricter still: a third-party payment handler can start network/payment work and then throw synchronously. Once module-owned native activation has begun, the OPC adapter cannot prove that no side effect occurred. Treating such a throw as a normal local failure and automatically releasing the reservation could reopen a second payment handoff while the first one is already progressing.

## Decision

1. The default finalization reservation TTL is 900 seconds (15 minutes).
2. Constructor customization remains bounded to 60..3600 seconds so configuration/code changes cannot accidentally create effectively unbounded reservations or a dangerously tiny duplicate-protection window.
3. Expiry remains based on database/server time (`UNIX_TIMESTAMP()`), avoiding browser-clock authority.
4. Explicit release remains scoped to the current shop/cart/customer plus the exact cryptographically random attempt ID.
5. Before an attempt-scoped release, the store asks Core `Order::getIdByCartId()` whether an order already exists for the cart. If an order exists, the reservation is preserved for normal successful-order cleanup.
6. If the Core order lookup throws or cannot be trusted, release fails closed and preserves the reservation. Its bounded TTL remains the recovery path.
7. Browser adapters may automatically release only while native module-owned activation is known not to have started. This includes preflight/network/DOM-validation failures and a payment form/control that disappears before invocation.
8. Immediately before invoking an ordinary payment form submit lifecycle or replaying a binary module click/form submit, the adapter crosses an explicit handoff-started boundary.
9. A synchronous third-party handler failure after that boundary does **not** call the release endpoint. The reservation remains active, the checkout is marked `data-jzopc-handoff-uncertain`, and all checkout controls are frozen so the browser cannot immediately submit a second payment attempt.
10. Recovery from an uncertain started handoff is owned by successful Core order lifecycle cleanup or the bounded reservation TTL. The OPC module does not guess that a thrown handler performed no side effects.
11. Expired-row cleanup remains bounded to at most 100 rows per purge operation.
12. No schema, hook or configuration migration is introduced, so the module version remains `0.4.0`.

## Security rationale

The downside of a longer reservation or a fail-closed uncertain-handoff state is a temporary retry delay after a hard browser/payment failure. The downside of releasing too early is materially worse: a second tab or retry can enter the native payment path while the first attempt may still be progressing out of process.

Likewise, an explicit release after Core order creation, an unknown Core order state, or ambiguous post-activation JavaScript failure would weaken the duplicate-handoff barrier exactly when checkout state is most sensitive. Failing closed prefers bounded temporary unavailability over duplicate order/payment risk.

The browser lock is defense in depth, not the security authority. Cross-tab/process protection remains the DB-backed reservation and Core order state. A page reload cannot remove that server-side barrier.

## Browser boundary after this change

The ordinary and binary adapters now encode the conservative source rule instead of leaving it only as a future browser requirement. Pre-handoff failures can release their exact attempt; once native `submit`/`click` invocation begins, a synchronous throw preserves the reservation and freezes the checkout.

Controlled browser verification is still mandatory because source inspection cannot prove the behavior of representative third-party handlers, redirects, embedded SDKs, popup flows or asynchronous payment initialization. The runtime matrix must verify that a partially acting handler cannot reopen a second handoff and that successful Core cleanup or TTL expiry restores the expected recovery path.

## Verification

`CheckoutFinalizationReservationRecoveryContractSmokeTest.php` records the TTL, bounded-release and Core-order-aware fail-closed store contract. `CheckoutFinalSubmitBrowserContractSmokeTest.php` now additionally records the browser source contract that automatic release stops at the native-activation boundary and that uncertain ordinary/binary handoffs remain frozen behind the reservation.

These tests were updated but not executed in this change because GitHub Actions quota remains exhausted and the connected environment has no local installed PrestaShop/browser runtime. They must not be treated as passing evidence until actually executed.

Real runtime/browser verification must still prove concurrent tabs, slow/abandoned payment initialization, successful lifecycle cleanup, explicit pre-handoff release, thrown/partial third-party handlers and retry after TTL expiry before the readiness gate can be reconsidered.

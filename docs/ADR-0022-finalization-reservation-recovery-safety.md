# ADR-0022: Finalization reservation recovery safety

## Status

Accepted for source implementation. Installed-runtime/browser verification remains pending and `INTEGRATION_SHELL_READY=false` remains unchanged.

## Context

The finalization reservation is the cross-tab/process barrier between a successful OPC preflight and the native PrestaShop/payment-module handoff. The previous 90-second default was intentionally short, but it is too aggressive for slow redirects, payment initialization, 3-D Secure preparation, overloaded shops or a browser that stalls after the payment module has started work.

A second recovery concern exists around explicit browser release. Release is useful before native payment handoff has begun, but it must never remove the barrier after Core has already created an order. A separate `Order::getIdByCartId()` check followed by a later reservation `DELETE` leaves a time-of-check/time-of-use window: Core can create an order after the check and before the delete. The order-absence predicate therefore has to participate in the same database statement that removes the exact attempt reservation.

The reservation also protects the cart itself, not merely one currently observed customer identity. A stale tab or identity transition can present the same cart with a different customer binding while an earlier payment handoff is still active. Deleting the existing row merely because `id_customer` differs would let stale traffic erase the cross-tab duplicate-handoff barrier and could reopen a second payment path. An unexpired reservation therefore remains authoritative for its shop/cart until exact owner release, successful Core order lifecycle cleanup, or TTL expiry.

Expiry cleanup itself is concurrency-sensitive. A reader can observe an expired shop/cart row, then lose the race to another request that removes that expired row and inserts a fresh active reservation. An unconditional `DELETE` by shop/cart after the stale read would then erase the new reservation. Expiry must therefore be revalidated in the deleting statement, and a zero-row delete must cause a fresh lookup rather than being interpreted as an empty barrier.

The browser boundary is stricter still: a third-party payment handler can start network/payment work and then throw synchronously. Once module-owned native activation has begun, the OPC adapter cannot prove that no side effect occurred. Treating such a throw as a normal local failure and automatically releasing the reservation could reopen a second payment handoff while the first one is already progressing.

## Decision

1. The default finalization reservation TTL is 900 seconds (15 minutes).
2. Constructor customization remains bounded to 60..3600 seconds so configuration/code changes cannot accidentally create effectively unbounded reservations or a dangerously tiny duplicate-protection window.
3. Expiry remains based on database/server time (`UNIX_TIMESTAMP()`), avoiding browser-clock authority.
4. An unexpired reservation is first resolved as a shop/cart-level handoff barrier. A request with a different current customer ID must not delete or overwrite that barrier.
5. Same-attempt idempotent success additionally requires the stored customer ID to equal the current cart customer together with the same state version, payment selection and attempt ID. A different customer or competing attempt fails closed as `CheckoutFinalizationReservationAlreadyActive`.
6. Customer mismatch is recovered only through the normal bounded mechanisms: exact owner/attempt release before native activation, successful Core order cleanup, or reservation TTL expiry. Stale traffic is not a cleanup authority.
7. When an expired row is observed, its cleanup `DELETE` repeats `expires_at <= UNIX_TIMESTAMP()` together with the exact shop/cart identity. The stale reader therefore cannot delete a replacement row that has already become active.
8. If that conditional expired-row `DELETE` affects zero rows, the store performs a fresh active-reservation lookup. It does not assume the cart is unreserved after losing the cleanup race.
9. Explicit release remains scoped to the current shop/cart/customer plus the exact cryptographically random attempt ID.
10. Attempt release is one SQL `DELETE` whose `WHERE` clause includes both the exact reservation identity/attempt and `NOT EXISTS` against the prefixed Core `orders` table for the same cart. The barrier cannot be deleted by a statement whose database snapshot already contains an order for that cart.
11. Release no longer performs a separate PHP/Core order lookup before deletion, removing that TOCTOU window. Database/query failure remains fail-closed because the release statement throws and the reservation is not intentionally removed; bounded TTL remains the recovery path.
12. Browser adapters may automatically release only while native module-owned activation is known not to have started. This includes preflight/network/DOM-validation failures and a payment form/control that disappears before invocation.
13. Immediately before invoking an ordinary payment form submit lifecycle or replaying a binary module click/form submit, the adapter crosses an explicit handoff-started boundary.
14. A synchronous third-party handler failure after that boundary does **not** call the release endpoint. The reservation remains active, the checkout is marked `data-jzopc-handoff-uncertain`, and all checkout controls are frozen so the browser cannot immediately submit a second payment attempt.
15. Recovery from an uncertain started handoff is owned by successful Core order lifecycle cleanup or the bounded reservation TTL. The OPC module does not guess that a thrown handler performed no side effects.
16. Expired-row cleanup remains bounded to at most 100 rows per purge operation.
17. No schema, hook or configuration migration is introduced, so the module version remains `0.4.0`.

## Security rationale

The downside of a longer reservation or a fail-closed uncertain-handoff state is a temporary retry delay after a hard browser/payment failure. The downside of releasing too early is materially worse: a second tab or retry can enter the native payment path while the first attempt may still be progressing out of process.

Likewise, an explicit release after Core order creation, an unknown database order state, ambiguous post-activation JavaScript failure, a stale customer binding, or a lost expired-row cleanup race must not weaken the duplicate-handoff barrier exactly when checkout state is most sensitive. Failing closed prefers bounded temporary unavailability over duplicate order/payment risk.

The browser lock is defense in depth, not the security authority. Cross-tab/process protection remains the DB-backed reservation and Core order state. A page reload, stale tab, customer-binding transition, or concurrent expiry cleanup cannot remove a newer server-side barrier merely because another request observed older state.

## Browser boundary after this change

The ordinary and binary adapters encode the conservative source rule instead of leaving it only as a future browser requirement. Pre-handoff failures can release their exact attempt; once native `submit`/`click` invocation begins, a synchronous throw preserves the reservation and freezes the checkout.

Controlled browser verification is still mandatory because source inspection cannot prove the behavior of representative third-party handlers, redirects, embedded SDKs, popup flows or asynchronous payment initialization. The runtime matrix must verify that a partially acting handler cannot reopen a second handoff and that successful Core cleanup or TTL expiry restores the expected recovery path.

## Verification

`CheckoutFinalizationReservationRecoveryContractSmokeTest.php` records the TTL, cart-level active-reservation ownership rule, same-customer idempotency requirement, race-safe expired-row cleanup, lost-cleanup-race re-read, exact attempt scoping, atomic Core-order-aware release predicate and bounded cleanup contract. `CheckoutFinalSubmitBrowserContractSmokeTest.php` records the browser source contract that automatic release stops at the native-activation boundary and that uncertain ordinary/binary handoffs remain frozen behind the reservation.

These tests were updated but not executed in this change because GitHub Actions quota remains exhausted and the connected environment has no local installed PrestaShop/browser runtime. They must not be treated as passing evidence until actually executed.

Real runtime/browser verification must still prove concurrent tabs, customer-binding transitions/stale tabs, expiry-cleanup contention, slow/abandoned payment initialization, successful lifecycle cleanup, explicit pre-handoff release, thrown/partial third-party handlers and retry after TTL expiry before the readiness gate can be reconsidered.

# ADR-0022: Finalization reservation recovery safety

## Status

Accepted for source implementation. Installed-runtime/browser verification remains pending and `INTEGRATION_SHELL_READY=false` remains unchanged.

## Context

The finalization reservation is the cross-tab/process barrier between a successful OPC preflight and the native PrestaShop/payment-module handoff. The previous 90-second default was intentionally short, but it is too aggressive for slow redirects, payment initialization, 3-D Secure preparation, overloaded shops or a browser that stalls after the payment module has started work.

A second recovery concern exists around explicit browser release. Release is useful before native payment handoff has begun, but it must never remove the barrier after Core has already created an order. If Core order state cannot be read reliably, deleting the reservation is less safe than leaving it to expire.

## Decision

1. The default finalization reservation TTL is 900 seconds (15 minutes).
2. Constructor customization remains bounded to 60..3600 seconds so configuration/code changes cannot accidentally create effectively unbounded reservations or a dangerously tiny duplicate-protection window.
3. Expiry remains based on database/server time (`UNIX_TIMESTAMP()`), avoiding browser-clock authority.
4. Explicit release remains scoped to the current shop/cart/customer plus the exact cryptographically random attempt ID.
5. Before an attempt-scoped release, the store asks Core `Order::getIdByCartId()` whether an order already exists for the cart. If an order exists, the reservation is preserved for normal successful-order cleanup.
6. If the Core order lookup throws or cannot be trusted, release fails closed and preserves the reservation. Its bounded TTL remains the recovery path.
7. Expired-row cleanup remains bounded to at most 100 rows per purge operation.
8. No schema, hook or configuration migration is introduced, so the module version remains `0.4.0`.

## Security rationale

The downside of a longer reservation is a temporary retry delay after a hard browser/payment crash. The downside of a reservation that expires too soon is materially worse: a second tab or retry can enter the native payment path while the first attempt may still be progressing out of process.

Likewise, an explicit release after Core order creation would weaken the duplicate-handoff barrier exactly when checkout state is most sensitive. Failing closed on unknown order state prefers bounded temporary unavailability over duplicate order/payment risk.

## Browser ambiguity follow-up

The store layer cannot prove whether a third-party JavaScript handler that throws has already initiated network/payment work. ADR-0023 now applies the conservative rule in both browser adapters: automatic attempt release remains available only while native module-owned activation definitely has not started. Once an ordinary submit lifecycle, binary click or binary form replay has begun, a thrown exception preserves the reservation for successful Core cleanup or bounded TTL recovery.

This source hardening does not replace the required controlled browser verification of thrown/partial native handlers.

## Verification

`CheckoutFinalizationReservationRecoveryContractSmokeTest.php` records the TTL, bounded-release and Core-order-aware fail-closed source contract. `CheckoutNativePaymentHandoffAmbiguityContractSmokeTest.php` separately locks the browser-side pre-activation versus post-activation release boundary introduced by ADR-0023.

These latest contracts have not been executed because GitHub Actions quota remains exhausted and the connected environment has no local installed PrestaShop/browser runtime.

Real runtime/browser verification must still prove concurrent tabs, slow/abandoned payment initialization, successful lifecycle cleanup, explicit pre-handoff release, thrown/partial native handlers and retry after TTL expiry before the readiness gate can be reconsidered.

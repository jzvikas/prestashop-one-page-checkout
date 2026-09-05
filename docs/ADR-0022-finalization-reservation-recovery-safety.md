# ADR-0022: Finalization reservation recovery safety

## Status

Accepted for source implementation. Installed-runtime/browser verification remains pending and `INTEGRATION_SHELL_READY=false` remains unchanged.

## Context

The finalization reservation is the cross-tab/process barrier between a successful OPC preflight and the native PrestaShop/payment-module handoff. The previous 90-second default was intentionally short, but it is too aggressive for slow redirects, payment initialization, 3-D Secure preparation, overloaded shops or a browser that stalls after the payment module has started work.

A second recovery concern exists around explicit browser release. Release is useful before native payment handoff has begun, but it must never remove the barrier after Core has already created an order. If Core order state cannot be read reliably, deleting the reservation is less safe than leaving it to expire.

## Decision

1. The effective finalization reservation TTL is 900 seconds (15 minutes).
2. Both the store constructor default and the installed service-container wiring use 900 seconds. The DI configuration must not retain a shorter legacy override that silently defeats the store default.
3. Constructor customization remains bounded to 60..3600 seconds so configuration/code changes cannot accidentally create effectively unbounded reservations or a dangerously tiny duplicate-protection window.
4. Expiry remains based on database/server time (`UNIX_TIMESTAMP()`), avoiding browser-clock authority.
5. Explicit release remains scoped to the current shop/cart/customer plus the exact cryptographically random attempt ID.
6. Before an attempt-scoped release, the store asks Core `Order::getIdByCartId()` whether an order already exists for the cart. If an order exists, the reservation is preserved for normal successful-order cleanup.
7. If the Core order lookup throws or cannot be trusted, release fails closed and preserves the reservation. Its bounded TTL remains the recovery path.
8. Expired-row cleanup remains bounded to at most 100 rows per purge operation.
9. No schema, hook or configuration migration is introduced, so the module version remains `0.4.0`.

## Security rationale

The downside of a longer reservation is a temporary retry delay after a hard browser/payment crash. The downside of a reservation that expires too soon is materially worse: a second tab or retry can enter the native payment path while the first attempt may still be progressing out of process.

Likewise, an explicit release after Core order creation would weaken the duplicate-handoff barrier exactly when checkout state is most sensitive. Failing closed on unknown order state prefers bounded temporary unavailability over duplicate order/payment risk.

A DI override is part of the runtime security boundary. Changing only a constructor default is insufficient if `services.yml` still injects a legacy value. The recovery contract therefore asserts both source default and installed container wiring.

## Browser ambiguity follow-up

The store layer cannot prove whether a third-party JavaScript handler that throws has already initiated network/payment work. ADR-0023 applies the conservative rule in both browser adapters: automatic attempt release remains available only while native module-owned activation definitely has not started. Once an ordinary submit lifecycle, binary click or binary form replay has begun, a thrown exception preserves the reservation for successful Core cleanup or bounded TTL recovery.

The browser also remains visibly fail-closed after ambiguous activation through the dedicated ambiguity guard, so the UI does not immediately advertise a retry while the server reservation is intentionally active.

This source hardening does not replace the required controlled browser verification of thrown/partial native handlers.

## Verification

`CheckoutFinalizationReservationRecoveryContractSmokeTest.php` records the effective TTL at both the store and service-container layers, bounded-release and Core-order-aware fail-closed source contract. `CheckoutNativePaymentHandoffAmbiguityContractSmokeTest.php` separately locks the browser-side pre-activation versus post-activation release boundary introduced by ADR-0023.

These latest contracts have not been executed because GitHub Actions quota remains exhausted and the connected environment has no local installed PrestaShop/browser runtime.

Real runtime/browser verification must still prove concurrent tabs, slow/abandoned payment initialization, successful lifecycle cleanup, explicit pre-handoff release, thrown/partial native handlers and retry after TTL expiry before the readiness gate can be reconsidered.

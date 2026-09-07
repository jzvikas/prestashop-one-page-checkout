# ADR-0062: Diagnose the Core free-order post-order stall before changing cleanup

## Status

Accepted for runtime diagnosis. Production checkout remains gated by `INTEGRATION_SHELL_READY=false`.

## Context

The exact-head PrestaShop 9.1.5 Native Payment Runtime for commit `45ad3672ce7a319e71432b6e88457ba354894943` advanced the zero-total scenario beyond the uncertainty covered by ADR-0061.

Chromium completed the OPC finalization request and submitted the canonical Core `POST /order-confirmation?free_order=1`. The runtime router recorded request entry but no request exit before the bounded browser timeout. The failure-only server probe then reported all of the following at the same time:

- exactly one order already existed for the free-order cart;
- `Order::getIdByCartId()` resolved an order;
- the OPC finalization reservation still existed;
- the canonical OPC selection row still existed and still selected `free_order`.

PrestaShop 9.1.5 `PaymentModule::validateOrder()` invokes `actionValidateOrderAfter` synchronously near the end of order validation. `OrderConfirmationController::checkFreeOrder()` can redirect to the final confirmation only after `PaymentFree::validateOrder()` returns. The observed state therefore narrows the open blocker to the post-order lifecycle after Core has already persisted the order. It does not justify changing Core order creation, synthesizing an OPC redirect, releasing the reservation early, or bypassing the order lifecycle hook.

The ordinary pinned `ps_checkpayment` runtime still completes Core order creation and OPC transient-state cleanup successfully. A free-order-only stall must therefore be diagnosed before changing production cleanup semantics shared with already-proven native payment flows.

## Decision

Extend the existing CLI-only, failure-only free-order diagnostic with a read-only database process summary while the timed-out Core request is still active.

The diagnostic may report only aggregate counts for:

1. non-sleeping database sessions for the current database;
2. sessions whose server state indicates a lock wait;
3. active statements classified server-side as an OPC finalization-row delete;
4. active statements classified server-side as an OPC selection-row delete.

The process summary excludes its own connection. SQL text, connection IDs, database users, hosts, arbitrary state text, request data and customer data are never emitted. Failure or lack of permission to read `information_schema.PROCESSLIST` is treated as unavailable supplemental evidence and must not hide the already-collected order/reservation evidence.

No production order, payment, reservation or cleanup code is changed by this diagnostic milestone.

## Security and compatibility consequences

- Core and payment modules remain the only order-creation owners.
- OPC still does not call `PaymentFree` or `validateOrder()`.
- The finalization reservation is not released merely because an order row exists while the Core request is incomplete.
- Cart/customer binding, CSRF, stale-state protection and cart mutex semantics are unchanged.
- The diagnostic remains restricted to `PHP_SAPI=cli`, `JZOPC_RUNTIME_ACTIVE_FIXTURE=1` and a resolved PrestaShop root below `/tmp/prestashop`.
- Process information is reduced inside SQL to aggregate classifications; raw process rows are not printed or serialized.
- An unavailable process list is fail-safe for diagnostics: the runtime gate still fails and the zero-total milestone remains unverified.
- `INTEGRATION_SHELL_READY` remains `false`.

## Verification

Source/smoke verification requires an exact-head CI result.

The diagnostic itself is useful only after an exact-head PrestaShop 9.1.5 Native Payment Runtime reaches the free-order timeout and emits the new aggregate process evidence. Until that happens, no database-lock hypothesis is considered proven and no production cleanup change should be made from it.

The zero-total milestone remains blocked until an executed runtime proves the full reserved Core free-order handoff, Core-owned exactly-one order creation, completed confirmation redirect/identity, refresh stability and removal of both OPC transient-state rows.

# ADR-0064: Trace the pre-`actionValidateOrderAfter` free-order Core lifecycle in the disposable PrestaShop fixture

## Status

Accepted for runtime diagnosis. Production checkout remains gated by `INTEGRATION_SHELL_READY=false`.

## Context

Exact-head PrestaShop 9.1.5 Native Payment Runtime `34114661032` on commit `305c61c84ea11a9f4e094e408778e6d082c951dc` proved that the ordinary `ps_checkpayment` control path reaches the OPC `actionValidateOrderAfter` hook, proves the Core order, completes both transient-row cleanup operations and returns normally.

The zero-total Core `free_order` path behaves differently. Its canonical `POST /order-confirmation?free_order=1` enters Core and creates exactly one Core order, but it does not reach even the first fixture-only `JZOPC_RUNTIME_ORDER_HOOK phase=enter` marker before the bounded browser timeout. The failure probe still finds one finalization reservation and one canonical `free_order` selection row, and the database-process probe reports no active database request or lock wait at the observation point.

This exonerates OPC `hookActionValidateOrderAfter()` and its cleanup repository operations as the source of the observed stall. PrestaShop 9.1.5 `PaymentModule::validateOrder()` performs several synchronous lifecycle phases after order persistence and before its final `actionValidateOrderAfter` dispatch, including `actionValidateOrder`, order-history/status handling, confirmation-mail preparation/sending, tax-detail update and stock synchronization.

Changing production OPC cleanup, releasing the reservation on mere order-row existence, creating a free order directly in OPC, or synthesizing an order-confirmation redirect would not address the observed boundary and could weaken exactly-once guarantees.

## Decision

Instrument only `/tmp/prestashop/classes/PaymentModule.php` in the disposable PrestaShop 9.1.5 runtime fixture with fixed, structure-only markers around the post-persistence lifecycle phases that precede `actionValidateOrderAfter`.

The instrumenter:

- is CLI-only and requires `JZOPC_RUNTIME_ACTIVE_FIXTURE=1`;
- accepts only the exact `/tmp/prestashop` path after `realpath()` resolution;
- requires the expected PrestaShop 9.1.5 source boundaries to occur exactly once before modifying anything;
- logs only fixed phase names and never IDs, request data, customer data, payment data, secure keys, CSRF values, headers or exception text;
- does not call `PaymentFree`, `validateOrder()`, order APIs or database write APIs;
- is installed by the already-isolated active runtime fixture builder before the local HTTP server starts.

The normal `ps_checkpayment` path remains the control sample and must continue through all phase markers and OPC cleanup. The zero-total path will identify the first Core phase that starts without completing.

## Security and compatibility consequences

No production PrestaShop Core file is changed by the repository. The only Core file mutation is inside the disposable `/tmp/prestashop` CI checkout. Repository production OPC source remains closed and does not contain Core trace markers.

Core/payment modules remain the sole order-creation owners. Server-authoritative cart/customer state, CSRF, stale-state checks, cart mutex, finalization reservation semantics and third-party payment/carrier hooks are unchanged.

The trace cannot be used as an alternative completion path. A Core-created order row alone is still insufficient to release an ambiguous finalization reservation or to manufacture a successful browser confirmation.

## Verification

Source/smoke verification requires exact-head CI. Runtime verification requires exact-head PrestaShop 9.1.5 Native Payment Runtime with both the ordinary payment control and the free-order scenario.

The zero-total milestone remains unverified until Core completes its own free-order request, the browser reaches Core order confirmation, refresh is stable, exactly one Core order exists for the cart, and both OPC transient rows are removed by the normal post-order lifecycle.

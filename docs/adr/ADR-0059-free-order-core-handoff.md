# ADR-0059: Preserve Core-owned zero-total order completion

## Status

Implemented with source/smoke coverage and a required PrestaShop 9.1.5 Chromium/runtime gate. The first executed runtime gate reached the zero-total checkout and final-submit request but timed out before Core order-confirmation navigation; this milestone remains unverified until the exact browser/runtime path succeeds.

## Context

PrestaShop 9.1.5 does not ask a merchant payment module to create a zero-total order. `PaymentOptionsFinder::findFree()` exposes a synthetic `free_order` payment option whose action targets Core `order-confirmation?free_order=1`. `OrderConfirmationController::checkFreeOrder()` performs duplicate detection and, only after rechecking the Core cart/customer/address/total state, creates the order through Core `PaymentFree::validateOrder()`.

The OPC payment presenter already delegated zero-total discovery to `PaymentOptionsFinder::present(true)`, but the final-submit path depended on a persisted merchant payment selection. A fresh zero-total checkout therefore had a correctness gap: Core could present its synthetic option while OPC finalization still rejected the checkout as having no selected payment method.

## Decision

Zero-total carts continue through the same OPC finalization safety boundary and the same Core-owned form action rather than introducing a module-owned order path.

1. The payment renderer preselects the synthetic option only when the server-derived cart total is free and Core presents exactly one unambiguous `free_order` option with the expected module identity.
2. At finalization begin, while already inside the existing cart mutex and CSRF/cart/customer/stale-state boundary, the mutation independently recomputes the cart total and asks a fresh `PaymentOptionsFinder()->present(true)` for the Core free-order option.
3. Only the exact single `free_order` shape is normalized into temporary server selections. Any missing, duplicated, malformed, hook-rewritten or exception-producing shape is left unresolved so the existing payment preflight fails closed.
4. The existing `CheckoutFinalizationPreflightService` then revalidates that normalized selection through `CheckoutPaymentSelectionService`, so a different option set between resolution and preflight is rejected.
5. The normal finalization reservation is acquired with the canonical `free_order:<option-id>` state key. The successful mutation persists that server selection together with the existing approved agreements.
6. Browser handoff remains the normal reserved native-form handoff. The synthetic form action points to Core order confirmation; the OPC module never calls `PaymentFree`, `PaymentModule::validateOrder()` or writes an order.
7. Successful Core order creation continues to trigger `actionValidateOrderAfter`, which removes OPC selection and reservation state.

## Security and correctness consequences

- Core remains the sole owner of free-order creation and duplicate protection.
- Zero-total status is derived from the loaded Core cart at finalization time, not from browser input.
- A cart changing from zero to positive total cannot retain free-order authority: fresh resolution is skipped and ordinary persisted-payment preflight applies.
- A hook that makes the synthetic free-order presentation ambiguous causes finalization to fail closed.
- The existing reservation still prevents concurrent OPC finalization attempts before Core receives the free-order request.
- No order, payment credential, CSRF token, address PII or customer payload is added to OPC persistence.
- Runtime fixture code is restricted to `/tmp/prestashop`, requires `JZOPC_RUNTIME_ACTIVE_FIXTURE=1`, and requires the installed module to resolve into `/tmp/jzopc-active-fixture*`.

## Verification

`CheckoutFreeOrderCoreHandoffContractSmokeTest.php` locks the Core finder use, exact single-option requirement, preflight reuse, reservation-backed handoff and prohibition on OPC-owned order creation.

The Native Payment Runtime contains a PrestaShop 9.1.5 zero-total gate. It creates a separate zero-price Core product only in the disposable runtime shop, then Chromium must complete guest identity, Core address/carrier state and agreements through normal OPC mutations. The browser requires one server-selected `free_order` option, a same-origin Core `order-confirmation?free_order=1` POST action, successful OPC finalization reservation, normal payment-handoff lifecycle, Core confirmation with `id_module=-1`, and stable cart/order identity after confirmation reload.

Executed Native Payment Runtime `34078730795` on commit `93da0e830dba9a17b1fd7ad251ab3e72d8d29228` passed the existing ambiguous-handoff, TTL-recovery and ordinary `ps_checkpayment` Core-order cleanup gates, then failed only in the new free-order Chromium step. The browser reached final submit and received the finalization HTTP response, but `page.waitForURL()` timed out after 30 seconds before any Core order-confirmation navigation was observed. The gate therefore remains red; the result must not be described as free-order completion evidence.

A separate read-only completion probe requires a loadable Core order with module `free_order`, zero paid totals, exactly one order for the cart, matching `Order::getIdByCartId()`, and zero remaining rows in both OPC finalization and selection tables. Neither fixture nor probe calls `PaymentFree`, `validateOrder()` or inserts an order.

Until the browser/runtime execution succeeds, zero-total completion remains a release blocker.

`INTEGRATION_SHELL_READY` remains `false` until this and the remaining representative payment/runtime gates are genuinely executed.

# ADR-0059: Preserve Core-owned zero-total order completion

## Status

Accepted as a source/smoke contract. Browser/runtime verification is still required before this milestone is release-verified.

## Context

PrestaShop 9.1.5 does not ask a payment module to create a zero-total order. `PaymentOptionsFinder::findFree()` exposes a synthetic `free_order` payment option whose action targets Core `order-confirmation?free_order=1`. `OrderConfirmationController::checkFreeOrder()` performs duplicate detection and, only after rechecking the Core cart/customer/address/total state, creates the order through Core `PaymentFree::validateOrder()`.

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

## Verification

A dedicated smoke contract locks the Core finder use, exact single-option requirement, preflight reuse, reservation-backed handoff and prohibition on OPC-owned order creation. The next runtime gate must execute an actual zero-total PrestaShop 9.1.x browser checkout through `order-confirmation?free_order=1`, prove exactly one Core-created order for the cart, verify duplicate/reload safety, and verify `actionValidateOrderAfter` cleanup.

`INTEGRATION_SHELL_READY` remains `false` until this and the remaining representative payment/runtime gates are genuinely executed.

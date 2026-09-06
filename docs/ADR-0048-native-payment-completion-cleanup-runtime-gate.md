# ADR-0048: Native payment completion and post-order cleanup runtime gate

## Status

Accepted for implementation; release verification is gated on executed PrestaShop 9.1.5 runtime results.

## Context

The checkout already has source and browser coverage for server-authoritative final preflight, DB-backed finalization reservations, same-cart concurrent attempts, exact idempotent replay, foreign release rejection, exact release, and browser-authoritative fail-closed fallback. Those gates intentionally stop before a payment module creates a real order.

That leaves one release-critical boundary unproven: after OPC reserves the handoff and delegates to a native payment-module form, a real payment module must remain the component that calls `PaymentModule::validateOrder()`, Core must create exactly one order, and `actionValidateOrderAfter` must remove the module-owned checkout selection and finalization reservation without changing the already-created order outcome.

## Decision

Add a dedicated PrestaShop 9.1.5 installed-runtime gate using the pinned official `ps_checkpayment` fixture already used for payment presentation coverage.

The browser contract must:

1. create a real Core cart through Front Office;
2. complete guest identity and address through normal OPC mutations;
3. select the Core carrier and the Core-presented `ps_checkpayment` option;
4. approve current Core legal agreements;
5. click the real OPC final-submit control;
6. observe successful OPC final preflight;
7. allow the selected native payment form to submit normally;
8. require navigation to Core order confirmation for the same cart;
9. report only numeric cart/order IDs to the runtime harness.

The browser contract and cleanup probe must never call `validateOrder()` or insert an order directly.

After browser completion, a CLI probe boots the installed shop and requires:

- the reported order is a real loaded Core `Order`;
- `Order::module` is exactly `ps_checkpayment`;
- the order belongs to the reported cart/shop;
- exactly one Core order exists for that cart;
- both `jzopc_checkout_finalization` and `jzopc_checkout_selection` contain zero rows for the completed cart;
- Core cart-to-order lookup resolves to the same order ID.

## Safety boundary

This gate does not make the OPC module an order creator. The official payment module owns `validateOrder()` and Core owns order persistence. OPC only performs its existing post-order transient-state cleanup from `actionValidateOrderAfter` after Core order existence is established.

The dedicated runtime workflow is intentionally pinned to PrestaShop 9.1.5 and the known `ps_checkpayment` revision so failures are attributable. Broader representative redirect, embedded/tokenized, binary/self-submitting and asynchronous callback payment modules remain separate compatibility milestones.

`INTEGRATION_SHELL_READY` remains `false` until this gate and the remaining required runtime/browser matrices are genuinely executed and green.

# ADR-0065: Split the Core free-order order-history call boundary

## Status

Accepted for runtime diagnosis. Production checkout remains gated by `INTEGRATION_SHELL_READY=false`.

## Context

Exact-head PrestaShop 9.1.5 Native Payment Runtime `34119596900` on commit `fce8bd51a54bb08e5b349a6d0cd2859bbe80fcc3` executed the disposable Core validate-order trace successfully and failed only at the bounded zero-total Core completion browser contract.

The ordinary official `ps_checkpayment` control completed every traced Core phase and the OPC successful-order cleanup. In the zero-total `free_order` path, Core accepted the canonical `POST /order-confirmation?free_order=1`, persisted exactly one order and completed `actionValidateOrder`, then emitted `JZOPC_RUNTIME_CORE_VALIDATE phase=history_begin` but never emitted `phase=history_end` before the browser timeout.

The failure-only server probe still found exactly one finalization reservation and one canonical `free_order` selection row. The database-process probe found no active database request, no lock wait and no active OPC transient-row delete at the observation point. Therefore the current evidence keeps OPC cleanup outside the observed stall boundary.

PrestaShop 9.1.5 `PaymentModule::validateOrder()` implements the traced history phase as two consecutive Core-owned calls: `OrderHistory::changeIdOrderState(...)` followed by `OrderHistory::addWithemail(...)`. The previous trace did not distinguish which call failed to return.

## Decision

Refine only the disposable `/tmp/prestashop/classes/PaymentModule.php` trace with four fixed structure-only markers around those two existing calls:

- `history_change_state_begin` / `history_change_state_end` around `changeIdOrderState(...)`;
- `history_add_with_email_begin` / `history_add_with_email_end` around `addWithemail(...)`.

The original `history_begin` / `history_end` markers remain, so the new evidence is backward-comparable with the executed control path.

The instrumenter also fail-closes unless the expected PrestaShop 9.1.5 `changeIdOrderState(...)` and `addWithemail(...)` source landmarks each occur exactly once. The smoke contract requires the new markers and landmarks.

No production OPC source or PrestaShop installation is modified. No Core method is skipped, retried, replaced or invoked by the diagnostic itself.

## Security and compatibility consequences

The diagnostic remains CLI-only, requires `JZOPC_RUNTIME_ACTIVE_FIXTURE=1`, accepts only the exact `/tmp/prestashop` target after `realpath()` resolution and logs only fixed phase names. It does not log cart/order/customer identifiers, request data, cookies, CSRF/auth material, secure keys, payment data, SQL or exception messages.

Core/payment modules remain the sole order-creation owners. OPC does not call `PaymentFree`, `PaymentModule::validateOrder()` or create/synthesize an order/confirmation. The server-authoritative cart/customer boundary, cart mutex, stale-state checks and finalization reservation semantics are unchanged.

An already-persisted Core order still does not authorize OPC to clear an ambiguous reservation or manufacture a confirmation redirect. Cleanup remains owned by the normal post-order lifecycle once Core reaches `actionValidateOrderAfter`.

## Expected runtime evidence

The next exact-head Native Payment Runtime can now distinguish the two remaining Core-owned branches:

- `history_change_state_begin` without `history_change_state_end` localizes the stall inside `OrderHistory::changeIdOrderState()`;
- `history_change_state_end` followed by `history_add_with_email_begin` without its end marker localizes the stall inside `OrderHistory::addWithemail()` / its email-history path;
- both end markers followed by a later stall moves diagnosis to the already-traced confirmation-mail/tax/stock phases.

This diagnostic milestone is not a successful zero-total checkout claim. The free-order gate remains open until Core completes its own request, Chromium reaches the canonical order confirmation, refresh remains stable, exactly one Core order exists for the cart and normal OPC lifecycle cleanup removes both transient rows.

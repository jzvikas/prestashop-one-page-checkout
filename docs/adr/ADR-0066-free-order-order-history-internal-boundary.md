# ADR-0066: Trace the Core free-order OrderHistory internal boundary

## Status

Accepted for runtime diagnosis. Production checkout remains gated by `INTEGRATION_SHELL_READY=false`.

## Context

Exact-head PrestaShop 9.1.5 Native Payment Runtime `34126842678` on commit `49e4194bc48d0a81acb868df6f37315cade03a7e` completed the ordinary official `ps_checkpayment` control, ambiguous-handoff reservation preservation and same-cart TTL recovery, then failed only at the bounded zero-total Core completion Chromium contract.

The free-order request reached Core `POST /order-confirmation?free_order=1`, persisted exactly one order and emitted these fixed Core validation markers before the timeout:

- `order_persisted`;
- `validate_hook_begin` / `validate_hook_end`;
- `history_begin`;
- `history_change_state_begin` / `history_change_state_end`;
- `history_add_with_email_begin`.

It did not emit `history_add_with_email_end`. The failure-only probe still found one finalization reservation and one canonical `free_order` selection row, while the aggregate DB-process probe found no active DB request, lock wait or OPC transient-row delete. Therefore the observed stall is inside Core `OrderHistory::addWithemail()` and still precedes OPC `actionValidateOrderAfter` cleanup.

PrestaShop 9.1.5 implements `addWithemail()` by calling the Core `add()` path, clearing the history cache and then calling `sendEmail()`. The `add()` path persists the order-history row, updates the order current state and executes `actionOrderHistoryAddAfter`. `sendEmail()` resolves the status-email metadata and, when enabled for the state, prepares optional attachments and delegates delivery to Core `Mail::Send()`.

## Decision

Extend only the disposable `/tmp/prestashop` runtime instrumentation. `tests/Runtime/InstrumentCoreValidateOrderTraceFixture.php` now also instruments `/tmp/prestashop/classes/order/OrderHistory.php` with fixed structure-only markers around:

- `add()` entry/return;
- history-row persistence;
- order current-state update;
- `actionOrderHistoryAddAfter`;
- history-cache cleanup;
- `sendEmail()` entry/return;
- status-email metadata lookup;
- mail-data preparation;
- attachment preparation;
- Core `Mail::Send()`.

The instrumenter fail-closes unless the expected PrestaShop 9.1.5 source landmarks are unique. The order-state update guard intentionally uses the full `current_state -> update()` landmark rather than a generic `$order->update()` count because PrestaShop 9.1.5 legitimately contains more than one order update in `OrderHistory.php`.

The smoke contract requires every new fixed marker and source boundary and continues to reject order creation calls, direct `validateOrder()` calls, SQL write literals, request/cookie/auth/CSRF/secure-key access and variable `error_log()` payloads.

## Security and compatibility consequences

This remains test-only instrumentation. It is CLI-only, requires `JZOPC_RUNTIME_ACTIVE_FIXTURE=1`, resolves only the exact `/tmp/prestashop` target and modifies only the disposable Core checkout fixture used by runtime CI.

No marker includes cart, order, customer, address, payment, email, SQL, token, cookie, request-body or exception data. The diagnostic does not skip, retry or replace any Core method and does not invoke order creation itself.

Core/payment modules remain the sole owners of order creation and confirmation. OPC does not call `PaymentFree` or `PaymentModule::validateOrder()`, does not synthesize a confirmation redirect and does not clear a finalization reservation merely because an order row already exists.

## Expected runtime evidence

The next exact-head Native Payment Runtime can distinguish the remaining branches without changing production behavior:

- `add_begin` without `add_end` localizes the stall to Core history persistence, order-state update or `actionOrderHistoryAddAfter`;
- the nested `history_row_persist`, `order_state_update` and `history_hook` pairs identify which of those subphases failed to return;
- `add_end` and `send_email_begin` without `send_email_end` moves the stall to the Core status-email path;
- `email_lookup`, `email_prepare`, `attachment_prepare` and `status_mail` markers then isolate metadata lookup, preparation/attachment generation or `Mail::Send()` itself.

This diagnostic milestone is not a successful zero-total checkout claim. The free-order gate remains open until Core completes its own request, Chromium reaches canonical order confirmation, refresh remains stable, exactly one Core order exists for the cart and normal OPC lifecycle cleanup removes both transient rows.

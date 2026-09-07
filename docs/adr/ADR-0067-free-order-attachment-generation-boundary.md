# ADR-0067: Split the Core free-order attachment-generation boundary

## Status

Accepted for runtime diagnosis. Production checkout remains gated by `INTEGRATION_SHELL_READY=false`.

## Context

Exact-head PrestaShop 9.1.5 Native Payment Runtime `34131891739` on commit `cbd10bf640ff6fa394c9bd22bab462239fb0d10c` completed the ordinary official `ps_checkpayment` flow, the direct ordinary-form submit guard, ambiguous-handoff reservation preservation and same-cart TTL recovery. It failed only at the bounded Core zero-total completion Chromium contract.

The zero-total request reached Core `POST /order-confirmation?free_order=1`, persisted exactly one Core order and progressed through `PaymentModule::validateOrder()` into `OrderHistory::addWithemail()`. The executed fixed-only trace showed successful history persistence, order-state update, `actionOrderHistoryAddAfter`, history-cache cleanup, status-email lookup and email-data preparation. The last emitted OrderHistory marker was `attachment_prepare_begin`; `attachment_prepare_end` was never emitted before the Chromium timeout.

Failure-only server evidence still reported exactly one Core order, one OPC finalization reservation and one canonical `free_order` selection row. The aggregate database-process probe reported no active DB request, lock wait or OPC transient-row delete. Therefore the observed request stall is inside Core `OrderHistory::sendEmail()` attachment preparation and still precedes OPC `actionValidateOrderAfter` cleanup.

PrestaShop 9.1.5 attachment preparation may switch the Core language context, load the order invoice collection, execute `actionPDFInvoiceRender`, construct/render an invoice PDF and/or construct/render a delivery-slip PDF before restoring the language context and calling `Mail::Send()`.

## Decision

Extend only the disposable `/tmp/prestashop` runtime instrumentation. `tests/Runtime/InstrumentCoreValidateOrderTraceFixture.php` now adds fixed structure-only begin/end markers around:

- invoice collection loading;
- `actionPDFInvoiceRender`;
- invoice PDF construction;
- invoice PDF rendering;
- invoice PDF filename generation;
- delivery-slip PDF construction;
- delivery-slip PDF rendering;
- delivery-slip filename generation;
- Core language-context restoration.

The existing outer `attachment_prepare_begin` / `attachment_prepare_end` and status-mail markers remain intact.

The instrumenter fail-closes unless the expected PrestaShop 9.1.5 source landmarks are unique. The smoke contract requires every new marker and exact source boundary while retaining its existing bans on order creation, direct `validateOrder()` invocation, SQL writes, request/cookie/auth/CSRF/secure-key access and variable diagnostic payloads.

## Security and compatibility consequences

This change is test-only. It is CLI-only, requires `JZOPC_RUNTIME_ACTIVE_FIXTURE=1`, resolves only the exact `/tmp/prestashop` target and modifies only the disposable Core fixture used by runtime CI.

No diagnostic marker contains cart, order, customer, address, payment, email, SQL, token, cookie, request-body, PDF content, filename or exception data. The trace surrounds Core calls without skipping, retrying, replacing or changing their arguments or return values.

The runtime gate must not be made green by disabling invoice generation, PDF hooks, PDF rendering, status email, `PaymentFree`, order history or other Core behavior. If the next trace identifies one of those Core subphases as the stall, the cause must be understood as either a real compatibility defect or a controlled-runtime fixture limitation before production OPC behavior is changed.

Core/payment modules remain the sole owners of order creation and confirmation. OPC does not call `PaymentFree` or `PaymentModule::validateOrder()`, does not synthesize a confirmation redirect and does not clear a finalization reservation merely because an order row already exists.

## Expected runtime evidence

The next exact-head Native Payment Runtime can distinguish whether zero-total completion stops while loading invoices, executing the PDF hook, constructing/rendering the invoice PDF, resolving its filename, constructing/rendering a delivery-slip PDF or restoring the language context. A completed `attachment_prepare_end` would instead move the remaining stall to Core `Mail::Send()` or later order-history lifecycle.

This milestone is diagnostic only. Zero-total remains a release blocker until the untouched Core request completes, Chromium reaches canonical order confirmation, refresh remains stable, exactly one Core order exists for the cart and normal OPC lifecycle cleanup removes both transient rows.

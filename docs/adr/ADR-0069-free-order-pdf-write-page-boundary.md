# ADR-0069: Free-order PDF write-page boundary

## Status

Accepted for diagnostic/runtime hardening. Production checkout takeover remains closed.

## Context

Exact-head Native Payment Runtime `34145883319` on `a0e2daedf5be2ee450f2a0dff46f85352772855d` executed the official pinned `ps_checkpayment` payment-order/cleanup path, ambiguous native-handoff reservation gate, and same-cart TTL recovery gate successfully before the Core zero-total completion scenario timed out.

The free-order browser request reached Core `POST /order-confirmation?free_order=1`, and Core persisted exactly one order for the trusted cart. Failure-only server evidence still showed one OPC finalization reservation and one canonical `free_order` selection. There was no active database lock wait and no active OPC finalization/selection delete. This remains post-order, pre-OPC-cleanup evidence; it does not justify an OPC-created order, synthetic confirmation redirect, or reservation release.

The fixed-only Core lifecycle trace progressed through `PaymentModule::validateOrder()`, `OrderHistory::changeIdOrderState()`, `OrderHistory::addWithemail()`, invoice attachment preparation, `actionPDFInvoiceRender`, and `PDF::render(false)`. Inside the method-scoped PDF trace, font setup, page-group start, template-object resolution, template hook assignment, header registration, pagination registration, and content registration all completed. The final marker was `JZOPC_RUNTIME_CORE_PDF phase=write_page_begin`; `write_page_end` was not reached before the bounded Chromium timeout.

PrestaShop 9.1.5 Core shows that `PDF::render()` delegates this boundary to `PDFGenerator::writePage()`. The exact `writePage()` implementation sets header/footer/body margins, calls TCPDF `AddPage()`, then renders invoice body HTML through `writeHTML($this->content, true, false, true, false, '')`. TCPDF `AddPage()` can invoke the overridden Core `PDFGenerator::Header()`, whose body also calls `writeHTML($this->header)`. `PDFGenerator::Footer()` similarly renders footer and pagination HTML. The previous outer `write_page_begin/end` pair therefore cannot distinguish page setup/header rendering from invoice body rendering.

## Decision

Extend the existing test-only `tests/Runtime/InstrumentCorePdfRenderTraceFixture.php` so the same guarded disposable fixture also instruments `/tmp/prestashop/classes/pdf/PDFGenerator.php`.

The instrumenter remains CLI-only, requires `JZOPC_RUNTIME_ACTIVE_FIXTURE=1`, and requires the target root to resolve exactly to `/tmp/prestashop`. It fails closed on the exact PrestaShop 9.1.5 `Header()`, `Footer()`, and `writePage()` method anchors. Each semantic replacement is required to be unique only inside its isolated method range; Core semantics elsewhere in `PDFGenerator.php` are not required to be globally unique.

The additional fixed-only markers separate:

- header HTML rendering;
- footer HTML rendering;
- footer font-family reset;
- pagination HTML rendering;
- header-margin setup;
- footer-margin setup;
- body-margin setup;
- TCPDF `AddPage()`;
- invoice body `writeHTML()`.

The trace does not log template HTML, PDF bytes, filenames, cart/order/customer identifiers, SQL, request payloads, cookies, CSRF/secure keys, payment data, exception messages, or customer PII. It does not call `PaymentFree`, `PaymentModule::validateOrder()`, order APIs, or database mutation APIs.

## Safety boundary

This diagnostic must not make the runtime green by suppressing invoice generation, PDF rendering, Core header/footer execution, TCPDF page creation, status email, hooks, or any other normal PrestaShop behavior. A controlled-runtime limitation may be corrected only after the new phase evidence identifies it and only if the official Core free-order lifecycle remains intact.

The existence of exactly one Core order is still insufficient to synthesize confirmation or to clear OPC transient state. The finalization barrier remains until the real Core lifecycle reaches the existing successful-order cleanup proof.

## Consequences

The next executed 9.1.5 Native Payment Runtime should distinguish at least these cases:

1. `add_page_begin` without `add_page_end`, with `header_html_begin` identifying a nested Core header/TCPDF HTML stall when present;
2. completed `add_page_end` followed by `content_html_begin` without `content_html_end`, localizing the stall to invoice body HTML rendering;
3. completed `content_html_end`, proving `PDFGenerator::writePage()` itself returned and moving investigation to the next already-instrumented Core PDF phase.

The smoke contract locks method-scoped source drift detection, the `/tmp/prestashop` isolation boundary, fixed-only phase markers, and the prohibition on order/database/request-data behavior.

`INTEGRATION_SHELL_READY=false` remains required until this zero-total completion gate and the remaining representative payment/carrier/browser/release gates are genuinely executed successfully.

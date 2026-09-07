# ADR-0068: Free-order PDF render phase boundary

## Status

Accepted for diagnostic/runtime hardening. Production checkout takeover remains closed.

## Context

Exact-head Native Payment Runtime `34136981227` on `9bfc9d680bbcbe7d11ca2ec75d1337335eb254b8` executed the ordinary pinned `ps_checkpayment` payment-order/cleanup, ambiguity-preservation and same-cart TTL-recovery gates successfully before failing the Core zero-total completion scenario.

The free-order browser request reached Core `POST /order-confirmation?free_order=1` and Core persisted exactly one order. Failure-only server evidence showed one remaining OPC finalization reservation and one canonical `free_order` selection, with no active OPC transient-row delete and no database lock wait.

The fixed-only Core lifecycle trace progressed through `actionValidateOrder`, `OrderHistory::changeIdOrderState()`, history-row persistence, order-state update, `actionOrderHistoryAddAfter`, history-cache cleanup, status-email lookup/data preparation, invoice collection, `actionPDFInvoiceRender`, and invoice `PDF` construction. The final completed boundary was `attachment_invoice_pdf_construct_end`; the final entered boundary was `attachment_invoice_pdf_render_begin`. `attachment_invoice_pdf_render_end` was not reached before the browser timeout.

PrestaShop 9.1.5 `PDF::render(false)` is still a broad Core-owned operation. It sets the language font, starts the page group, resolves the PDF template object and module template hook, renders header/pagination/content, writes the TCPDF page, renders the footer, and finally delegates to the configured PDF renderer output. The outer `PDF::render(false)` marker therefore does not yet identify which Core PDF subphase stalls.

Exact-head Native Payment Runtime `34141650613` on `fd37bdd07696887d0a740464d7af7ba72a075144` did not reach Chromium. The temporary active fixture failed closed while installing the PDF trace with `Unexpected PrestaShop 9.1.5 PDF render source boundary.` Source review against the exact PrestaShop 9.1.5 `classes/pdf/PDF.php` showed that `$template = $this->getTemplateObject($object);` is legitimately present both in `PDF::render()` and later in `PDF::setFilename()`. Requiring that render semantic to be globally unique across the entire class was therefore an instrumentation preflight defect, not checkout evidence.

## Decision

Use the test-only instrumenter `tests/Runtime/InstrumentCorePdfRenderTraceFixture.php`, which may modify only `/tmp/prestashop/classes/pdf/PDF.php` when the explicit active-fixture environment guard is present.

The instrumenter now fails closed on unique method anchors for `PDF::render()` and the following `getTemplateObject()` method, isolates only that render-method source range, and then requires every traced semantic to be unique inside the isolated render range. Replacements are also performed only inside that range before the untouched prefix/suffix of Core `PDF.php` are reassembled. This preserves strict source drift detection without incorrectly rejecting a valid same semantic in another Core method such as `setFilename()`.

It emits fixed structural markers only for:

- language-font setup;
- page-group start;
- PDF template-object resolution;
- template hook assignment;
- header rendering;
- pagination rendering;
- content rendering;
- TCPDF page writing;
- footer rendering;
- final renderer output.

The trace never logs cart/order/customer identifiers, SQL, request bodies, cookies, CSRF/secure keys, payment data, template content, PDF bytes, filenames, exception messages, or customer PII. It does not call `PaymentFree`, `validateOrder()`, order creation APIs, or database mutation APIs.

`build-active-checkout-fixture.sh` installs this trace only after the production module has been copied to the explicitly permitted `/tmp/jzopc-active-fixture*` target. Production repository Core files are never patched.

## Safety boundary

The new evidence must not be used to make the runtime green by disabling invoice creation, `actionPDFInvoiceRender`, PDF rendering, status email, or any other Core behavior that a real PrestaShop checkout would execute. If the next trace identifies a controlled-runtime harness limitation, the harness may be corrected only if Core semantics remain intact and the same browser contract still proves official Core free-order completion and OPC post-order cleanup.

The existence of the Core order remains insufficient to synthesize an order-confirmation redirect or to release the OPC finalization reservation. Cleanup remains owned by the successful Core lifecycle and `actionValidateOrderAfter` proof.

## Consequences

The corrected fixture must first prove that the disposable PrestaShop 9.1.5 PDF trace can be installed. The next failed free-order browser run should then identify the first Core PDF phase entered without a matching end marker. A successful run must still prove the real Core confirmation redirect, exactly one order for the trusted cart, and cleared OPC transient state.

The smoke contract explicitly rejects a return to global render-semantic uniqueness and requires method-scoped extraction/replacement. This is a diagnostic regression guard only; no production checkout/payment/order behavior changed.

`INTEGRATION_SHELL_READY=false` remains required until this and the remaining payment/carrier/browser/release gates are genuinely executed successfully.

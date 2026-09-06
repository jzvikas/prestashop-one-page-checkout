# ADR-0054: Preserve native payment lifecycle evidence across navigation

## Status

Accepted for the native payment completion runtime milestone. `INTEGRATION_SHELL_READY` remains `false`.

## Context

The exact-head PrestaShop 9.1.5 native-payment runtime after ADR-0053 progressed beyond the previous malformed `sections=[]` response failure. Chromium began the official payment-module navigation so quickly that Playwright could no longer read the already-observed finalization response body. Moving the body read into the `waitForResponse()` continuation still raced with the same native navigation.

This is a runtime-harness boundary, not a reason to delay, replace, mock, or emulate native payment navigation in production. The production mutation client already parses and validates the finalization JSON before it emits `jzopc:checkout:final-preflight-completed`; the payment handoff guard then emits `jzopc:checkout:payment-handoff` immediately before the native form submit.

## Decision

The native payment browser contract no longer attempts a redundant Playwright read of the finalization response body. Instead it uses navigation-safe, server-authoritative and browser-authoritative evidence:

- the real finalization POST must return HTTP `< 400`;
- a Node-side trace, fed through a Playwright exposed binding, must observe `jzopc:checkout:final-preflight-completed` and `jzopc:checkout:payment-handoff` before navigation;
- the trace must observe neither `payment-submit-blocked` nor `payment-handoff-ambiguous`;
- Chromium must send an actual POST to the official `ps_checkpayment` validation endpoint;
- Core must navigate to order confirmation with the original OPC cart identity and positive Core order/module identities;
- the post-browser server-side probe must prove exactly one Core-owned order and no remaining OPC reservation/selection.

The lifecycle counters are stored in the Node process, not in the page, so order-confirmation navigation cannot erase the evidence. A smoke contract locks these requirements and also forbids reintroducing a Playwright finalization-body read.

This is stricter than treating the HTTP status alone as preflight success: native payment validation cannot satisfy the gate unless the production browser mutation client first accepted the response and emitted the success lifecycle event.

## Security and ownership

No production JavaScript or PHP behavior is changed by this ADR. The OPC module still does not create orders or call `PaymentModule::validateOrder()`. Native payment modules and PrestaShop Core continue to own payment validation, order creation, and confirmation navigation. The test does not block, rewrite, mock, fulfill, or synthetically complete the payment request, and it does not weaken CSRF/cart/customer/state binding or reservation semantics.

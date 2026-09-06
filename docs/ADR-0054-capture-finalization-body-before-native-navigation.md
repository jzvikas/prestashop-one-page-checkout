# ADR-0054: Capture finalization response before native payment navigation

## Status

Accepted for the native payment completion runtime milestone. `INTEGRATION_SHELL_READY` remains `false`.

## Context

The exact-head PrestaShop 9.1.5 native-payment runtime after ADR-0053 progressed beyond the previous malformed `sections=[]` response failure. The browser began native payment handoff quickly enough that Playwright navigated away while the test was still trying to call `preflightResponse.json()` on the already-observed finalization response. Chromium then reported that the response body was unavailable because the page had navigated away.

This is a runtime-harness race, not a reason to delay, replace, or emulate the native payment-module navigation in production. The browser application itself already consumes the finalization JSON before it authorizes payment handoff; the test must observe that same ordering without holding payment navigation back.

## Decision

The native payment browser contract starts finalization JSON capture immediately when Playwright observes the matching finalization response. `page.waitForResponse(...).then(async response => ...)` records the HTTP status and parses the JSON before the main test flow awaits the result after clicking the final-submit button.

The test still independently requires:

- successful finalization payload;
- an actual POST request to the official `ps_checkpayment` validation endpoint;
- Core order-confirmation navigation;
- matching Core cart identity;
- positive Core order/module identities;
- the post-browser server-side probe proving exactly one Core-owned order and no remaining OPC reservation/selection.

A source smoke contract prevents regression to reading the finalization body only after native handoff navigation may already have started.

## Security and ownership

No production JavaScript or PHP behavior is changed by this ADR. The OPC module still does not create orders or call `PaymentModule::validateOrder()`. Native payment modules and PrestaShop Core continue to own payment validation, order creation, and confirmation navigation. The test does not block, rewrite, mock, or synthetically complete the payment request.

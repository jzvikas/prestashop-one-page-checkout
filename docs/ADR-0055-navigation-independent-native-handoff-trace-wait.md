# ADR-0055: Keep native handoff trace synchronization outside page navigation

## Status

Accepted for the native payment completion runtime milestone. `INTEGRATION_SHELL_READY` remains `false`.

## Context

The exact-head native-payment runtime on commit `63f3482a588c2b62225849c0898d5493e36f4590` reached the official `ps_checkpayment` validation POST after successful OPC finalization preflight. The runtime browser contract then executed `page.waitForFunction(() => true, ..., { timeout: 100 })` only to yield briefly before inspecting lifecycle counters that were already stored in the Node process through an exposed Playwright binding.

That wait was coupled to the checkout page execution context. Native payment navigation can destroy that context immediately after the validation POST starts, so an otherwise meaningless always-true page function can timeout while Core/payment-module navigation is progressing normally. This is a runtime-harness race and is not evidence that the production final-submit, payment handoff, order creation, or cleanup logic is incorrect.

ADR-0054 already made the lifecycle evidence navigation-safe by storing counters in Node. The synchronization step must follow the same boundary.

## Decision

The native payment browser contract now waits for the required `final-preflight-completed` and `payment-handoff` counters entirely in Node:

- no page evaluation is performed after the official payment-module validation POST merely to yield time;
- a bounded two-second Node-side loop reads only the already-captured structural lifecycle counters;
- the loop yields through the Node event loop in short intervals so exposed binding callbacks can settle even while Chromium replaces the checkout document;
- after the bounded wait, the existing fail-closed assertions still require at least one preflight event and one handoff event, with zero blocked and zero ambiguous events;
- the real Chromium POST to official `ps_checkpayment`, Core order-confirmation navigation, original cart binding, positive order/module identity, exactly-one-Core-order probe, and transient OPC cleanup remain mandatory.

A smoke contract forbids reintroducing the post-handoff `page.waitForFunction(() => true)` synchronization and requires the navigation-independent Node-side wait.

## Security and ownership

This ADR changes test synchronization only. Production PHP and JavaScript behavior is unchanged.

The OPC module still does not call `PaymentModule::validateOrder()` and does not create orders directly. The official payment module and PrestaShop Core remain authoritative for validation, order creation, duplicate protection, and confirmation navigation. The runtime test does not intercept, fulfill, rewrite, mock, or synthesize the payment request or response.

No CSRF token, browser cookie, payment payload, customer payload, request body, or response body is added to diagnostics. Server-authoritative state, cart/customer binding, finalization reservation semantics, cart mutex behavior, stale-state protection, and fail-closed handoff behavior are unchanged.

The release gate remains closed until the native payment runtime itself executes successfully through Core order creation and post-order OPC cleanup, and the other required payment/browser compatibility gates are completed.

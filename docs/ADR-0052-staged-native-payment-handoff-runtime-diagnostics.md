# ADR-0052: Stage native-payment handoff runtime diagnostics

## Status

Accepted as a release-gate diagnostic contract. `INTEGRATION_SHELL_READY` remains `false`.

## Context

Native Payment Runtime run `34060916020` on `d23e631e69d83e81d2b60105dd9b4590f36e50e5` and exact-head run `34061475981` on `7c0490f549d1154d1990af0834184db943b9e2e5` both completed the PrestaShop 9.1.5 fixture, official pinned `ps_checkpayment` installation, Core carrier/payment eligibility, guest identity, address, carrier, payment selection, agreements and successful OPC finalization reservation. Both then timed out waiting for Core `order-confirmation`.

The second run proved that deferring the action-only low-level form submission beyond the active submit event did not resolve the failure. Continuing to change production handoff code without knowing whether Chromium emitted the `ps_checkpayment` validation request would be guesswork.

## Decision

Make the native-payment browser gate stage-aware while keeping diagnostics structurally safe.

Before final submit the gate now verifies the selected action-only form has:

- `method=POST`;
- the OPC action-only marker;
- a connected DOM form;
- a non-empty parsed action pathname.

During the handoff it separately observes:

1. successful OPC finalization response;
2. guarded final-preflight and payment-handoff browser events;
3. whether a request targeting the official `ps_checkpayment` validation controller actually leaves Chromium;
4. the validation response HTTP status when available;
5. whether navigation reaches Core `order-confirmation`.

On failure the gate may report only structural fields such as method, booleans/counters, HTTP status and URL pathname. It must not report query strings, form payloads, request/response headers, cookies, CSRF tokens, customer data or response bodies.

The matcher accepts both friendly `/module/ps_checkpayment/validation` routing and the equivalent Core query-routed controller internally, but the query itself is never emitted to logs.

## Security and ownership

This diagnostic contract does not alter production checkout behavior. The OPC module still:

- never calls `PaymentModule::validateOrder()`;
- never creates orders directly;
- keeps the finalization reservation after an ambiguous post-reservation outcome;
- leaves official payment-module/Core order creation and redirect ownership intact;
- fails closed if the runtime cannot prove that the native payment handoff completed.

## Verification

A future runtime may close this diagnostic milestone only after the staged output identifies the failing boundary. The actual release blocker remains stronger: an executed PrestaShop 9.1.5 browser run must reach the official payment validation controller, let `ps_checkpayment`/Core create exactly one order, reach Core order confirmation, verify `actionValidateOrderAfter` removed both OPC transient rows, and prove replay/refresh does not create a duplicate order.

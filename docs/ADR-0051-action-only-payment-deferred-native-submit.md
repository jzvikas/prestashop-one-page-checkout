# ADR-0051: Defer action-only native form submission beyond the submit event

## Status

Accepted for runtime verification. `INTEGRATION_SHELL_READY` remains `false`.

## Context

Exact-head Native Payment Runtime run `34060916020` on `d23e631e69d83e81d2b60105dd9b4590f36e50e5` executed the PrestaShop 9.1.5 fixture, Core carrier/payment eligibility, browser guest identity, native address, carrier, official pinned `ps_checkpayment` selection, agreements and finalization reservation successfully. The browser then timed out waiting for Core `order-confirmation` after final preflight had already returned success.

The action-only handoff introduced by ADR-0050 used `requestSubmit()` to expose one guarded submit event. The capture-phase guard consumed the exact reservation authorization, prevented that observable event and synchronously called `HTMLFormElement.prototype.submit.call(form)` from inside the still-active submit dispatch.

The official `ps_checkpayment` option is action-only: it supplies `PaymentOption::setAction()` and no module-owned form markup or submit handler. Its validation controller owns `validateOrder()` and redirects to Core order confirmation. OPC must reach that action without creating an order itself.

## Decision

Keep the ADR-0050 action-only marker and the same exact server-reserved authorization boundary, but do not reenter the native form submission algorithm while the observable submit event is still dispatching.

For an OPC-generated action-only form only:

1. the authorized `requestSubmit()` event is still observed and consumed by `ordinary-payment-submit-guard.js`;
2. the guard prevents and stops that synthetic observable event so no second handler path runs;
3. the exact connected form is retained;
4. `HTMLFormElement.prototype.submit.call(form)` is scheduled through `Promise.resolve().then(...)`, after the current submit dispatch has fully unwound;
5. if the form is no longer connected at that boundary, submission is not attempted and the existing reservation remains the fail-closed recovery barrier until Core cleanup or TTL expiry.

Module-provided `option.form` markup is unchanged. Those forms keep their native/requestSubmit/jQuery handler lifecycle because tokenization, embedded widgets and module-owned JavaScript remain third-party ownership. Binary/self-submitting options remain on their dedicated replay path.

The OPC module still never invokes `PaymentModule::validateOrder()` and never creates an order directly.

## Security properties

- unreserved direct observable submit remains capture-blocked;
- authorization remains exact payment-option/form scoped and one-shot;
- moving the low-level action-only submit to a microtask does not create a reusable authorization window because authorization is cleared before scheduling;
- a disconnected form fails closed rather than submitting a replacement or reconstructed payment target;
- no payment inputs, action URL, customer data, CSRF material or Core order state are synthesized by the browser adapter;
- ambiguous post-reservation outcomes retain the DB reservation and recover only through successful Core order cleanup or bounded TTL.

## Verification

Source smoke coverage must reject a synchronous `stopImmediatePropagation()` -> `HTMLFormElement.prototype.submit.call(form)` sequence for action-only forms and require the deferred connected-form handoff instead.

The release gate remains an executed PrestaShop 9.1.5 native-payment browser result proving all of the following on the same cart:

- the official pinned `ps_checkpayment` validation action is reached;
- Core/payment-module code creates exactly one order;
- the browser reaches Core order confirmation;
- `actionValidateOrderAfter` removes both OPC selection and finalization reservation rows;
- refresh/replay does not create a duplicate order.

Until that result is genuinely green, native payment completion remains unverified and `INTEGRATION_SHELL_READY=false`.

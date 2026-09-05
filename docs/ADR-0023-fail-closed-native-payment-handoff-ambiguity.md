# ADR-0023: Fail-closed native payment handoff ambiguity

## Status

Accepted for source implementation. Real installed-runtime/browser verification remains pending and `INTEGRATION_SHELL_READY=false` remains unchanged.

## Context

The OPC finalization reservation protects the boundary between a successful server-authoritative preflight and PrestaShop/payment-module-owned order/payment creation. ADR-0022 already established that explicit release is useful only while recovery is known to be safe and that uncertain Core order state must preserve the reservation.

There is an equivalent browser ambiguity boundary. A third-party payment handler invoked through jQuery submit, `requestSubmit()`, an original binary control click, or a binary form submit can start remote payment work, create an order through its native PrestaShop path, open an SDK flow, or dispatch another asynchronous action and then throw synchronously. A JavaScript exception therefore does not prove that nothing happened.

Automatically releasing the reservation after such an exception can reopen a second handoff while the first native payment action is already progressing. That creates a higher duplicate-payment/order risk than temporarily blocking a retry.

## Decision

### Ordinary payment forms

`final-submit-controller.js` keeps attempt-scoped release for failures that occur before a native payment form is activated, including a payment form that disappeared between final preflight and handoff.

Once the controller invokes the native module-owned submit lifecycle through any of:

1. jQuery `submit` trigger;
2. `requestSubmit()`;
3. raw `HTMLFormElement.prototype.submit.call()` fallback;

an exception is treated as ambiguous progress. The browser does not send `finalizationAction=release` for that exception. It emits `jzopc:checkout:payment-handoff-ambiguous`, reports the handoff failure to the UI, and leaves the DB-backed reservation intact.

### Binary/self-submitting payment options

`binary-payment-controller.js` explicitly tracks `nativeActivationStarted`.

Failures before the original module-owned click/form is invoked may still best-effort release the exact attempt because native activation is known not to have begun. Immediately before replaying the original click or submit lifecycle the flag is set to true.

If the replay then throws, the browser treats progress as ambiguous, emits the same `jzopc:checkout:payment-handoff-ambiguous` event, and preserves the reservation rather than guessing that release is safe.

### Ambiguous browser state lock

`payment-handoff-ambiguity-guard.js` consumes the ambiguity lifecycle event and moves the rendered checkout into an explicit fail-closed browser state.

The lock is applied in the next microtask because the ordinary/binary controllers still finish their synchronous local failure cleanup after emitting the event. This ordering prevents normal cleanup from re-enabling controls after the ambiguity guard has decided that another customer action is unsafe.

The guard:

- marks the checkout root with `data-jzopc-payment-handoff-ambiguous=true`;
- keeps the root `aria-busy=true`;
- disables module-rendered checkout buttons/inputs/selects/textareas;
- replaces the final status with a translated warning that payment processing may already have started and the order must not be submitted again;
- upgrades the status announcement to `role=alert` / assertive live-region semantics;
- emits `jzopc:checkout:payment-handoff-locked` for diagnostics/integration;
- never sends a release request, never submits payment and never creates an order.

This browser lock is UX/defense in depth only. The DB reservation remains the authoritative duplicate-handoff barrier.

### Recovery authority

After ambiguous native activation, recovery is intentionally server/Core-owned:

- successful Core order creation triggers `actionValidateOrderAfter` cleanup;
- explicit release remains Core-order-aware and fail-closed on unknown order state;
- if no order is created and the browser/payment flow is abandoned, the bounded finalization reservation TTL eventually restores retry availability.

The OPC module still never calls `PaymentModule::validateOrder()` or creates an order directly.

## Security rationale

A false-positive reservation hold causes bounded temporary unavailability. A false-negative release can allow two native payment handoffs for the same cart. Checkout safety therefore requires preferring the bounded hold whenever module-owned activation may already have started.

This rule also prevents JavaScript exception behavior from becoming an implicit duplicate-order bypass around the server reservation. Keeping the browser visibly locked also prevents the UI from suggesting that a retry is safe while the server intentionally still owns an active reservation.

## Consequences

- Pre-activation failures remain recoverable without waiting for TTL.
- Post-activation exceptions no longer cause automatic browser release.
- Ambiguous post-activation checkout UI remains visibly locked instead of immediately accepting more edits/retries.
- Payment modules keep their native submit/click lifecycle and remain responsible for their own order/payment mechanics.
- A broken third-party handler can leave the customer temporarily blocked until Core cleanup or reservation expiry; this is an intentional safety tradeoff.
- Browser/runtime testing is still required to prove behavior with real redirect, embedded and binary payment modules.

## Verification

`CheckoutNativePaymentHandoffAmbiguityContractSmokeTest.php` records the source contract for ordinary and binary adapters, including the closed readiness gate and the rule that post-activation exceptions cannot automatically release the reservation.

`CheckoutPaymentHandoffAmbiguityUiLockContractSmokeTest.php` records the translated UI-lock/asset contract and verifies that the guard cannot release, submit or create orders.

The new/updated smoke, JavaScript syntax, installed-runtime and browser tests have not been executed in this milestone because the repository GitHub Actions free quota remains exhausted and the connected repository environment does not provide an installed browser runtime. They must not be treated as passing evidence until actually executed.

Before readiness can change, controlled browser testing must still prove at minimum:

- a handler that throws before native activation can recover safely;
- ordinary submit handlers that begin work and then throw cannot reopen a second handoff;
- binary click/form handlers that begin work and then throw cannot reopen a second handoff;
- ambiguous UI remains locked after controller cleanup and does not permit an immediate visible retry;
- successful Core order creation clears the reservation;
- abandoned ambiguous attempts recover only through the bounded TTL when no order exists;
- concurrent-tab retries remain blocked while the original reservation is active.

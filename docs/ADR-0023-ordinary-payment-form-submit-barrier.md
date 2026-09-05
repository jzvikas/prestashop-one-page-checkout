# ADR-0023: Ordinary payment form submit barrier

## Status

Accepted for source implementation. Browser/runtime verification remains pending and `INTEGRATION_SHELL_READY=false` remains unchanged.

## Context

PrestaShop payment options may provide a complete module-owned `PaymentOption::form`. The OPC intentionally preserves that markup and keeps its fields enabled so embedded/tokenization integrations and native successful controls remain compatible.

That compatibility creates a final-submit edge: a module-provided ordinary (non-binary) form can contain its own visible submit control. Before this change the generic final-submit controller guarded only the OPC `Place order` button. A normal browser submit of the module-owned form could therefore enter the payment module's native action without first passing the OPC finalization preflight and DB-backed reservation.

Binary/self-submitting options already have a dedicated capture/preflight/replay controller, so the missing boundary applies to ordinary Core-presented forms.

## Decision

1. Add a dedicated `ordinary-payment-submit-guard.js` browser adapter.
2. Observe ordinary module-owned form `submit` events in capture phase at the OPC root, before target/bubble-phase third-party submit handlers.
3. If the selected option is ordinary and its exact Core-presented form has not been authorized by the final-submit handoff, call `preventDefault()` and `stopImmediatePropagation()`.
4. Do not disable or rewrite third-party form fields. Embedded fields, tokenization controls, hidden successful controls and Core/module form markup remain intact.
5. Binary options are explicitly ignored by this guard and remain owned by `binary-payment-controller.js`.
6. The existing `jzopc:checkout:payment-handoff` event is the narrow browser authorization boundary. `final-submit-controller.js` emits it only after successful server finalization preflight/reservation and immediately before invoking the native payment form lifecycle.
7. Authorization is bound to the exact selected option ID and exact connected form node.
8. Authorization is one-shot: the first observable authorized submit consumes it. A microtask also revokes unused authorization after the current synchronous handoff stack, covering jQuery paths that do not surface a native submit event to the capture listener.
9. Payment-option changes and checkout-section replacement revoke stale authorization.
10. The guard never calls `validateOrder()` and never creates an order. Native payment modules/Core continue to own payment processing and order creation.

## Security and compatibility rationale

The server-side finalization reservation remains the cross-tab/process duplicate-handoff authority. This browser guard closes a normal user-interaction route that could otherwise bypass creation of that reservation.

The guard is deliberately scoped to the observable native `submit` lifecycle. Hostile or third-party JavaScript can invoke low-level submission APIs in ways no independent browser listener can reliably police, and client-side state is never a security authority. Representative embedded/form modules therefore still require controlled browser verification before production takeover is enabled.

Keeping payment form fields enabled is important compatibility behavior: disabling them would remove successful controls from the native submission and can break payment SDK/tokenization workflows. The new barrier blocks the submit event rather than mutating the module form payload.

## Verification

`tests/Smoke/CheckoutOrdinaryPaymentSubmitGuardContractSmokeTest.php` records the source contract: capture-phase blocking, ordinary/binary separation, exact-form authorization, one-shot expiry, section/payment-change revocation, preserved third-party form markup and the closed readiness gate.

The contract has been source-reviewed but not executed in this milestone because GitHub Actions quota remains exhausted. It must not be described as passing evidence until actually executed.

Required browser verification remains: visible ordinary module submit buttons, Enter-key submission, jQuery/native submit handlers, embedded/tokenization forms, handler prevent-default/retry behavior, redirect flows, section replacement and concurrent-tab finalization.

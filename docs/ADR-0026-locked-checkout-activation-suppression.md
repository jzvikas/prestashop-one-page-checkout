# ADR-0026: Locked checkout activation suppression

## Status

Accepted for source implementation. Browser/runtime verification remains pending and `INTEGRATION_SHELL_READY=false` remains unchanged.

## Context

ADR-0023 through ADR-0025 establish a fail-closed browser state whenever native payment progress is ambiguous or an active finalization reservation is known from initial render or a live `finalization_in_progress` response.

The first UI lock disabled native `button`, `input`, `select` and `textarea` controls. That is not sufficient for the payment compatibility surface. PrestaShop binary/self-submitting payment modules may expose link-style activation through `a[href]`, and third-party UI can also use ARIA button surfaces. These elements do not support the native `disabled` property.

Leaving them active does not bypass the server DB reservation, but it lets a customer repeatedly invoke payment/finalization handlers from a browser that is supposed to be fail closed. It also makes the visual/accessibility state inconsistent with the duplicate-handoff barrier.

## Decision

1. The ambiguity/reservation guard continues to disable native form controls when it locks the checkout.
2. Link-style and ARIA button activation surfaces inside the checkout are marked `aria-disabled="true"` and removed from normal keyboard tab order while the lock is active.
3. The guard installs document-level capture listeners for `click` and `submit`.
4. Those listeners do nothing for normal checkout traffic. They suppress an event only when its target belongs to a checkout root already marked `data-jzopc-payment-handoff-ambiguous`.
5. Suppression calls `preventDefault()` and `stopImmediatePropagation()` before root/payment-module handlers receive the activation, covering link navigation and form-submit event paths that cannot be blocked with the native disabled property alone.
6. The guard remains local-only: it does not call `fetch`, send finalization actions, release reservations, submit payment itself or create orders.

## Security and compatibility rationale

The DB reservation and Core/payment-module order path remain authoritative. Event suppression is defense in depth that keeps the locked browser surface consistent with that authority.

Capture-phase suppression is intentionally limited to the already locked checkout root. Normal payment controls, third-party hooks/forms and their event handlers are untouched before a reservation/ambiguity lock exists.

A third-party script could still invoke its own network logic programmatically without a user click/submit event; the OPC module must not monkey-patch arbitrary module code to prevent that. The controlled browser matrix remains responsible for proving representative payment modules behave correctly around this boundary.

## Consequences

- Binary payment links cannot be repeatedly activated by pointer or normal keyboard activation while the checkout is reserved/ambiguous.
- Native form submits inside the locked checkout are stopped before module handlers/default submission.
- Normal unlocked checkout payment compatibility remains unchanged.
- No schema, configuration, endpoint or module-version migration is introduced.

## Verification

`CheckoutReservationUiEventSuppressionContractSmokeTest.php` records the source contract for link-style binary activators, ARIA disabled state, capture-phase click/submit suppression, absence of network/order side effects and the closed readiness gate.

The contract and real browser behavior have not been executed in this milestone because GitHub Actions free quota remains exhausted and no local installed PrestaShop/browser runtime is available. They must not be described as passing evidence.

Before activation, browser verification must still prove representative ordinary/binary payment controls are fully usable while unlocked and cannot be reactivated after initial-render, live-conflict or post-native-activation ambiguity locks.

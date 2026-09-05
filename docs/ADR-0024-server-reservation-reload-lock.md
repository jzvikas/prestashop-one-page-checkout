# ADR-0024: Server-reservation reload lock

## Status

Accepted for source implementation. Installed-runtime/browser verification remains pending and `INTEGRATION_SHELL_READY=false` remains unchanged.

## Context

The finalization reservation is intentionally allowed to survive browser crashes, redirects, navigation and ambiguous native payment failures for a bounded period. Before this decision, a fresh checkout page render did not expose that active server reservation to the browser. The DB barrier still rejected another finalization attempt, but the page could misleadingly render normal editable checkout controls and an apparently available final-submit path until the user tried again.

That mismatch is undesirable for both safety and UX. A server-authoritative checkout should not present a "ready to submit" state while its own duplicate-handoff reservation says that native payment progress may already be active.

## Decision

1. `CheckoutShellRenderer` queries `CheckoutFinalizationReservationStoreInterface::isActive()` for the current loaded cart/customer while rendering the trusted checkout shell.
2. The template exposes only a boolean `data-jzopc-finalization-reserved="1|0"` marker. It does not expose attempt IDs, payment selection, expiry timestamps or any other reservation internals.
3. `payment-handoff-ambiguity-guard.js` consumes that trusted marker on initial page load. An active reservation immediately reuses the same fail-closed ambiguity UI state used after a post-activation exception.
4. The browser disables mutable checkout controls, keeps the root busy state and announces the translated warning that payment may already have started and the order must not be submitted again.
5. The browser guard does not release the reservation, poll order state, submit payment or create orders. Recovery remains owned by successful Core order cleanup or bounded reservation expiry.
6. Expired/customer-mismatched reservations continue to be resolved by the existing server store before the boolean marker is produced.

## Security rationale

The DB reservation remains the real duplicate-order protection. This change removes a misleading browser state that could encourage repeated interaction while the server is intentionally blocking a second handoff.

Only a boolean is exposed, so no new client authority or sensitive reservation material crosses the trust boundary.

## Consequences

- Reload/back navigation during an active handoff remains visibly fail-closed.
- A customer may see a temporary locked checkout after an abandoned payment attempt until the reservation expires; this is consistent with the deliberate duplicate-payment safety tradeoff.
- A normal checkout with no active reservation is unaffected.
- No schema/config/hook migration or module version bump is required.

## Verification

`CheckoutReservedReloadUiContractSmokeTest.php` records the source contract across renderer, template, browser guard and the closed readiness gate.

The contract and browser behavior have not been executed in this milestone because GitHub Actions quota remains exhausted and no local installed PrestaShop/browser runtime is available. Real browser verification must still prove reload/back navigation during active, expired and successfully-cleaned reservations before readiness can change.

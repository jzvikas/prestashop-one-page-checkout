# ADR-0025: Live reservation concurrent-tab UI convergence

## Status

Accepted for source implementation. Installed-runtime/browser verification remains pending and `INTEGRATION_SHELL_READY=false` remains unchanged.

## Context

ADR-0024 made page reload/back navigation fail closed when the server already has an active finalization reservation at render time. A different race remains when two checkout tabs were both rendered before either had a reservation.

If tab A completes finalization preflight first, the DB reservation correctly blocks tab B. The server already exposes this through the stable `finalization_in_progress` machine code for both normal checkout mutations and competing finalization attempts. Before this decision, however, the browser paths did not converge consistently after receiving that authoritative response:

- generic identity/address/carrier/payment/agreement mutations published a validation failure but the ambiguity guard ignored it;
- ordinary final submit published the failure and then restored its local controls;
- binary final submit did not publish the shared validation lifecycle at all.

The database barrier prevented duplicate native handoff, but the losing tab could still appear editable and retryable, encouraging repeated blocked requests and presenting browser state that contradicted the server-owned reservation state.

## Decision

1. `finalization_in_progress` remains the single stable machine code for an active same-cart finalization reservation.
2. `checkout-mutation-client.js`, `final-submit-controller.js` and `binary-payment-controller.js` all publish `jzopc:checkout:validation-failed` with the server-provided error list when their guarded operation fails normally.
3. `payment-handoff-ambiguity-guard.js` listens for that lifecycle event and reacts only when the exact `finalization_in_progress` code is present.
4. On that server-authoritative conflict, the guard records only the boolean local fact `data-jzopc-finalization-reserved="1"` and schedules the existing fail-closed lock in a microtask.
5. The microtask ordering is deliberate: individual controllers may finish synchronous failure cleanup after publishing the event, so the convergence lock must run after that cleanup and remain the final browser state.
6. The same lock disables mutable controls, keeps `aria-busy=true` and announces the existing translated payment-progress warning.
7. The guard does not poll, call the finalization endpoint, release a reservation, submit a payment form or create an order. The DB reservation, Core successful-order cleanup and bounded TTL remain the recovery authorities.

## Security rationale

A browser can synthesize a validation event or modify its own dataset and therefore can force only a local self-lock. It cannot gain checkout authority, clear the server barrier or create an order through this mechanism.

Conversely, ignoring a genuine guarded `finalization_in_progress` response leaves a stale browser surface that contradicts the authoritative server workflow. Converging to the locked state is the safer failure mode and reduces repeated concurrent handoff pressure.

## Consequences

- A tab that loses a finalization race becomes visibly fail closed without requiring a reload.
- Generic checkout mutations and ordinary/binary final-submit paths now converge on the same browser state for an active reservation.
- Initial-render reservation detection from ADR-0024 remains the reload/back path; this ADR covers reservations acquired after the page was rendered.
- No new server endpoint, schema, configuration, polling loop or order-creation logic is introduced.
- A false browser-side lock is recoverable by reload and cannot weaken server security.

## Verification

`CheckoutConcurrentFinalizationUiConvergenceContractSmokeTest.php` records the source contract across the server machine code, generic mutation client, ordinary and binary final-submit adapters, browser ambiguity guard and the closed readiness gate.

The new/updated PHP/JavaScript/smoke/browser behavior has not been executed in this milestone because GitHub Actions free quota remains exhausted and the connected repository environment has no installed PrestaShop/browser runtime. It must not be treated as passing evidence until those gates execute.

Before readiness can change, controlled browser testing must still prove at minimum:

- two pre-opened tabs racing ordinary final submit leave exactly one active native handoff and lock the losing tab;
- binary final-submit conflicts publish the same server validation lifecycle and lock the losing tab;
- a normal checkout mutation in an older tab locks after another tab acquires the reservation;
- Core order cleanup and reservation expiry restore normal checkout only through a fresh authoritative page state;
- no conflict path causes the OPC module to create an order or release another attempt's reservation.

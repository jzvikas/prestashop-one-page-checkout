# Changelog

All notable repository changes are recorded here. Runtime/browser verification status is kept separate from implementation status; unexecuted checks are not treated as passing.

## Unreleased

### Added

- Front Office fail-closed HTTP runtime contract covering native `/order` non-takeover, absent OPC checkout assets/root and direct finalization-endpoint rejection while readiness remains closed.
- Source smoke contract locking the fail-closed HTTP workflow wiring and the production `INTEGRATION_SHELL_READY=false` boundary.
- ADR-0021 documenting the external HTTP activation-boundary test and its non-goals.
- PrestaShop 9.0.3 to the installed-runtime matrix as the explicit 9.0 legacy checkout-render family.
- `CheckoutRuntimeMatrixContractSmokeTest.php` to lock the 9.0/9.1/9.2 workflow/runtime-family contract and current finalization lifecycle baseline.
- ADR-0020 documenting the PrestaShop 9.0 installed-runtime coverage decision and verification status.
- Shop-scoped Back Office activation page using PrestaShop `HelperForm` and `AdminModules` security context.
- Strict server-side activation validation against runtime capabilities, native `ps_onepagecheckout` conflict and the internal readiness gate.
- Bounded opportunistic garbage collection for abandoned transient checkout-selection rows.
- `docs/COMPATIBILITY.md` and ADR-0019 documenting rollout and multistore activation rules.
- `CheckoutFinalizationReservationRecoveryContractSmokeTest.php` and ADR-0022 documenting the hardened duplicate-handoff recovery window and order-aware release rule.
- Final-submit browser contract coverage for the post-activation fail-closed boundary on ordinary and binary payment handoffs.
- `ordinary-payment-submit-guard.js` to block observable direct submission of selected ordinary Core-presented payment forms before OPC finalization preflight/reservation.
- `CheckoutOrdinaryPaymentSubmitGuardContractSmokeTest.php` and ADR-0023 documenting the exact-form, one-shot ordinary payment handoff authorization boundary.
- `payment-handoff-ambiguity-guard.js` to lock a checkout after reload when the server still owns an active finalization reservation, after `finalization_in_progress`, or after an ambiguous post-activation payment-handler failure.
- `CheckoutFinalizationReservationBrowserGuardContractSmokeTest.php` and ADR-0024 documenting reload-safe reservation UX, shared ambiguity lifecycle and the binary post-activation `AbortError` rule.

### Changed

- Finalization reservation default TTL increased from 90 seconds to 900 seconds, with constructor overrides bounded to 60..3600 seconds, so slow redirect/payment initialization cannot reopen the handoff barrier prematurely.
- Attempt-scoped reservation release now refuses to delete the barrier after Core reports an order for the cart; Core order-state lookup failure also fails closed and leaves bounded TTL recovery in control.
- Ordinary and binary payment adapters no longer automatically release a reservation after module-owned native activation has begun. If a third-party handler throws after submit/click invocation starts, checkout remains frozen behind the reservation until Core successful-order cleanup or bounded TTL recovery.
- Binary payment preflight now publishes `jzopc:checkout:validation-failed`, and `AbortError` is considered a harmless abort only before native click/form activation starts; the same error name after activation is treated as ambiguous and preserves the reservation.
- A server-rendered active finalization reservation now locks the checkout after page reload; the same lock is entered after a `finalization_in_progress` server rejection and suppresses link-style as well as form-style payment activation.
- Ordinary payment form controls remain enabled and untouched for native successful controls/embedded integrations, but a capture-phase guard now blocks normal direct submit before the reserved final-submit handoff. Authorization is exact option/form scoped, consumed by the first observable submit and revoked after the current synchronous handoff stack or payment/section change.
- Installed runtime workflow now starts a loopback Front Office server and executes the same fail-closed HTTP boundary contract for the 9.0, 9.1 and 9.2 runtime families.
- Installed runtime contracts now explicitly accept 9.0/9.1/9.2 families, with 9.0 and 9.1 sharing the legacy `actionCheckoutRender` path.
- Removed stale exact `0.3.0` runtime assertion; the installed contract now requires at least the `0.4.0` finalization-schema baseline.
- Installed runtime contract now verifies `actionValidateOrderAfter` successful-order cleanup hook registration.
- Architecture/security/compatibility documentation remains synchronized with the finalization, native payment handoff, successful-order cleanup, abandoned-selection cleanup and ordinary payment direct-submit barrier already present in code.

### Verification

- GitHub Actions are available again. CI #110 on `10563dc63dc0416e56e59cfab3e935e2a6a39180` completed successfully through Composer metadata, PHP syntax, JavaScript syntax and the full smoke suite.
- The installed 9.1/9.2 runtime jobs have passed module installation, Core process adapter and Installed Smarty shell contracts. The current module-front session fallback check is still being corrected in its CLI harness and is not yet considered passing evidence.
- The ADR-0024 reload/ambiguity browser hardening is new in this delta and requires its own CI/runtime execution before it is considered verified.
- Controlled active-browser checkout/payment coverage remains unexecuted on current `main`; no browser production-readiness claim is made.

### Safety

- `INTEGRATION_SHELL_READY` remains `false`; these changes do not enable production checkout takeover.
- Reservation release remains exact customer/attempt scoped; uncertain Core order state and ambiguous post-activation payment-handler failure preserve the duplicate-handoff barrier instead of weakening it.
- Reload/same-tab reservation locking is browser defense in depth only; server DB reservation and Core order state remain the authoritative duplicate-handoff boundary.
- Direct ordinary module-form submit blocking changes only the observable browser submit lifecycle; it does not rewrite payment payloads, disable payment form fields before handoff, call `validateOrder()` or claim to police low-level hostile/module JavaScript submission.
- The HTTP contract does not create carts/orders or call payment order-creation APIs; it only checks external fail-closed behavior.
- No module version bump: browser reservation/submit policy, runtime-matrix/test/documentation changes introduce no new schema/config/hook migration.

## 0.4.0

### Added

- Full finalization preflight under the shared CSRF/cart/customer/stale-state/cart-mutex boundary.
- DB-backed finalization reservation with attempt-scoped release and short-lived recovery semantics.
- Native ordinary payment-form handoff preserving jQuery submit handlers, `requestSubmit()` and a final raw-submit compatibility fallback.
- Binary/self-submitting payment preflight and original module control/form replay.
- Core-owned zero-total `free_order` handoff.
- Successful-order lifecycle cleanup for module-owned selection and finalization-reservation state.
- Upgrade schema for finalization reservation storage.

## 0.3.0

### Added

- Version-specific checkout process adapters for PrestaShop 9.0/9.1 and 9.2+.
- `actionFrontControllerSetMedia` registration and upgrade hook lifecycle.
- Trusted checkout shell/bootstrap and frontend asset registration infrastructure.

## 0.2.0

### Added

- Server-persisted canonical payment/agreement checkout selections in `jzopc_checkout_selection`.
- Upgrade schema for existing installations.

## 0.1.0

### Added

- Initial production-oriented PrestaShop 9 module skeleton, version capability detection, server-authoritative state model and CI/smoke-test foundation.

# Changelog

All notable repository changes are recorded here. Runtime/browser verification status is kept separate from implementation status; unexecuted checks are not treated as passing.

## Unreleased

### Added

- `CheckoutReservedReloadUiContractSmokeTest.php` and ADR-0024 covering fail-closed initial rendering when the current cart already has an active server-side finalization reservation.
- `CheckoutPaymentHandoffAmbiguityUiLockContractSmokeTest.php` and `payment-handoff-ambiguity-guard.js`, which keep the browser checkout visibly fail-closed after ambiguous native payment activation instead of presenting an immediate retry path.
- `CheckoutNativePaymentHandoffAmbiguityContractSmokeTest.php` and ADR-0023 documenting the fail-closed boundary between safe pre-activation release and ambiguous post-activation native payment progress.
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

### Changed

- Checkout shell rendering now reads the authoritative finalization reservation store and exposes only a boolean reserved marker. Reload/back navigation during an active reservation immediately reuses the fail-closed ambiguity UI lock instead of presenting editable checkout controls and an apparently available submit path.
- Fixed a runtime DI drift that still injected a legacy 90-second finalization TTL despite the store default being hardened to 900 seconds. Installed service wiring now uses the same 900-second payment-safe window, and the recovery smoke contract asserts both layers so the override cannot silently regress.
- After an ambiguous native payment exception, the browser now waits until submit-controller cleanup finishes, marks the checkout as ambiguous, disables mutable checkout controls, keeps `aria-busy=true`, and announces a translated assertive warning that the order must not be submitted again while payment progress is unknown.
- Ordinary native payment-form exceptions no longer automatically release the finalization reservation after the module-owned submit lifecycle has started; ambiguous progress now preserves the duplicate-handoff barrier for Core cleanup or bounded TTL recovery.
- Binary payment replay now explicitly tracks whether the original module-owned click/form activation has started. Pre-activation errors may release their exact attempt; exceptions after activation starts preserve the reservation and emit the `jzopc:checkout:payment-handoff-ambiguous` lifecycle event.
- Finalization reservation effective TTL increased from 90 seconds to 900 seconds, with constructor overrides bounded to 60..3600 seconds, so slow redirect/payment initialization cannot reopen the handoff barrier prematurely.
- Attempt-scoped reservation release now refuses to delete the barrier after Core reports an order for the cart; Core order-state lookup failure also fails closed and leaves bounded TTL recovery in control.
- Installed runtime workflow now starts a loopback Front Office server and executes the same fail-closed HTTP boundary contract for the 9.0, 9.1 and 9.2 runtime families.
- Installed runtime contracts now explicitly accept 9.0/9.1/9.2 families, with 9.0 and 9.1 sharing the legacy `actionCheckoutRender` path.
- Removed stale exact `0.3.0` runtime assertion; the installed contract now requires at least the `0.4.0` finalization-schema baseline.
- Installed runtime contract now verifies `actionValidateOrderAfter` successful-order cleanup hook registration.
- Architecture/security documentation remains synchronized with the finalization, native payment handoff, successful-order cleanup and abandoned-selection cleanup already present in code.

### Verification

- Active-reservation reload locking and its new smoke contract are source-reviewed but unexecuted while GitHub Actions quota is exhausted; real reload/back-navigation behavior remains a browser gate rather than verified evidence.
- Effective 900-second DI wiring and its strengthened reservation-recovery smoke assertion are source-reviewed but unexecuted while GitHub Actions quota is exhausted; installed runtime behavior is not considered verified until the deferred matrix executes.
- Ambiguous-handoff UI locking and its new smoke contract are source-reviewed but unexecuted while GitHub Actions quota is exhausted; real DOM/event ordering and payment-handler behavior remain browser gates rather than verified evidence.
- Native handoff ambiguity hardening and its new smoke contract are source-reviewed but unexecuted while GitHub Actions quota is exhausted; real ordinary/binary thrown-handler behavior remains a browser gate, not verified compatibility evidence.
- Reservation recovery hardening and its smoke contract remain unexecuted while GitHub Actions quota is exhausted; they are not considered passing runtime/browser evidence.
- The fail-closed HTTP runtime contract, its workflow execution and its new smoke contract are source-reviewed but unexecuted while GitHub Actions quota is exhausted; they are not considered passing runtime evidence.
- The new/updated 9.0 runtime and smoke contracts are source-reviewed but unexecuted while GitHub Actions quota is exhausted; they are not considered passing compatibility evidence yet.

### Safety

- `INTEGRATION_SHELL_READY` remains `false`; these changes do not enable production checkout takeover.
- The server-reserved reload marker is boolean-only; no attempt ID, payment selection, expiry or other reservation internals are exposed to the browser, and the browser guard never releases the reservation or creates an order.
- The ambiguity UI guard is defense in depth only and never sends finalization release, submits payment or creates an order; the DB reservation remains authoritative.
- Browser-side release is intentionally forbidden once native payment activation may have started; bounded temporary retry blocking is preferred over reopening a duplicate native handoff.
- Reservation release remains exact customer/attempt scoped; uncertain Core order state preserves the duplicate-handoff barrier instead of weakening it.
- The HTTP contract does not create carts/orders or call payment order-creation APIs; it only checks external fail-closed behavior.
- No module version bump: reservation policy, effective DI TTL alignment, reload UI safety, payment-handoff browser safety, runtime-matrix/test/documentation changes introduce no new schema/config/hook migration.

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

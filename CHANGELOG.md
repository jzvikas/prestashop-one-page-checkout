# Changelog

All notable repository changes are recorded here. Runtime/browser verification status is kept separate from implementation status; unexecuted checks are not treated as passing.

## Unreleased

### Added

- `IntegrationFailureIsolationContract.php`, its installed-matrix wiring, source contract and ADR-0028. The runtime gate injects a test-local shell persistence-read failure without changing production readiness/configuration: PrestaShop 9.0/9.1 must retain the exact original Core checkout process/session, while the 9.2 provider path must consume already-prepared HTML without re-entering risky shell dependencies after provider selection.
- Canonical `scripts/run-smoke-tests.sh` used by CI and documented local development. It forces `zend.assertions=1`, fails if the smoke directory resolves to zero test files, and prevents legacy PHP `assert()` checks from silently becoming no-ops under local/CI ini differences.
- `CheckoutSmokeRunnerContractSmokeTest.php` locking the assertion-enabled canonical runner and preventing CI from drifting back to a duplicate smoke-loop implementation.
- `CheckoutIntegrationFailureContainmentContractSmokeTest.php` and ADR-0027 covering eager shell preparation, request-local failure containment and Core native checkout fallback before version-specific process takeover.
- `CheckoutReservationUiEventSuppressionContractSmokeTest.php` and ADR-0026 covering capture-phase suppression of link/form activation after a checkout reservation/ambiguity lock.
- `CheckoutConcurrentFinalizationUiConvergenceContractSmokeTest.php` and ADR-0025 covering live same-cart reservation conflicts when another tab acquires finalization after the current page was rendered.
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

- Installed Smarty shell runtime coverage now requires a non-empty server-generated finalization URL targeting `finalize`, exactly one final-submit/status surface, and a fresh-cart server reservation marker of exactly `0`. This prevents the installed matrix from passing while the current final-submit bootstrap is incomplete.
- Runtime-matrix source coverage now requires `IntegrationFailureIsolationContract.php` to exist, accept 9.0/9.1/9.2 explicitly and remain wired into the installed workflow.
- CI smoke execution now delegates to `scripts/run-smoke-tests.sh` rather than maintaining an independent loop; the documented local command uses the same runner.
- Checkout shell composition now completes before version-specific process takeover. PrestaShop 9.0/9.1 keeps Core's original process reference until the replacement is fully prepared; PrestaShop 9.2+ returns no provider if preparation fails, allowing Core to build native checkout.
- Required checkout asset, activation-policy and process-preparation failures now trip a request-local circuit breaker so later hooks in the same request cannot partially re-enable OPC takeover. Fallback logging is limited to stage, exception class and numeric shop/cart identifiers.
- `CheckoutShellStep` now consumes already-prepared HTML while still rendering through Core `renderTemplate()`, preserving the checkout-step template hook without invoking risky DB/template/third-party shell composition after takeover.
- Locked reservation/ambiguity state now covers non-form payment surfaces: link-style/ARIA activators are marked disabled and removed from tab order, while capture-phase click and submit listeners stop user activation before third-party payment handlers or browser navigation can run inside the locked checkout.
- A live `finalization_in_progress` server response now converges generic checkout mutations plus ordinary and binary final-submit paths to the same fail-closed browser lock. The losing concurrent tab records only the boolean reserved state, waits until controller cleanup finishes, disables mutable controls and does not present an immediate retry surface.
- Binary final-submit failures now publish the shared `jzopc:checkout:validation-failed` lifecycle with server errors, matching ordinary submit and generic mutation behavior so reservation conflicts can be handled consistently.
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

- The canonical smoke-runner mechanism was executed directly with local PHP 8.4.23: an enabled true assertion exits successfully, while an enabled false assertion raises `AssertionError` and returns a non-zero exit status. This verifies the runner's assertion-enablement mechanism only; it is not a claim that the full repository smoke suite executed in the current environment.
- The new installed integration failure-isolation contract, strengthened installed Smarty finalization assertions and their 9.0/9.1/9.2 workflow execution are source-reviewed but unexecuted because GitHub is still creating no checks/statuses for the current branch.
- Integration failure containment and its updated versioned-process/source contracts are source-reviewed but unexecuted while GitHub Actions runners are unavailable; DB/template/service/asset failure injection on real 9.0/9.1/9.2 request paths remains mandatory before fallback is considered proven.
- Locked activation-surface suppression and its new smoke contract are source-reviewed but unexecuted while GitHub Actions runners are unavailable; real link-style binary and form-submit behavior remains a browser compatibility gate.
- Live concurrent-tab reservation convergence and its new smoke contract are source-reviewed but unexecuted while GitHub Actions runners are unavailable; real two-tab ordinary/binary/mutation behavior remains a browser gate rather than verified evidence.
- Active-reservation reload locking and its new smoke contract are source-reviewed but unexecuted while GitHub Actions runners are unavailable; real reload/back-navigation behavior remains a browser gate rather than verified evidence.
- Effective 900-second DI wiring and its strengthened reservation-recovery smoke assertion are source-reviewed but unexecuted while GitHub Actions runners are unavailable; installed runtime behavior is not considered verified until the deferred matrix executes.
- Ambiguous-handoff UI locking and its new smoke contract are source-reviewed but unexecuted while GitHub Actions runners are unavailable; real DOM/event ordering and payment-handler behavior remain browser gates rather than verified evidence.
- Native handoff ambiguity hardening and its new smoke contract are source-reviewed but unexecuted while GitHub Actions runners are unavailable; real ordinary/binary thrown-handler behavior remains a browser gate, not verified compatibility evidence.
- Reservation recovery hardening and its smoke contract remain unexecuted while GitHub Actions runners are unavailable; they are not considered passing runtime/browser evidence.
- The fail-closed HTTP runtime contract, its workflow execution and its new smoke contract are source-reviewed but unexecuted while GitHub Actions runners are unavailable; they are not considered passing runtime evidence.
- The new/updated 9.0 runtime and smoke contracts are source-reviewed but unexecuted while GitHub Actions runners are unavailable; they are not considered passing compatibility evidence yet.

### Safety

- `INTEGRATION_SHELL_READY` remains `false`; these changes do not enable production checkout takeover.
- Installed failure injection exists only under `tests/Runtime`: it does not write activation configuration, modify/reflect the readiness constant, add debug endpoints, submit finalization actions or create orders.
- Native-fallback containment only declines/prevents custom process takeover; it does not mutate cart state, release finalization reservations, submit payment or create orders. Exception messages and request/payment payloads are not added to fallback logs.
- Locked event suppression is limited to a checkout already marked reserved/ambiguous and has no network/finalization/order side effects; unlocked third-party payment controls keep their native handlers.
- Live reservation convergence consumes only the guarded server machine error and can cause only a local browser lock; it does not poll, release reservations, submit payment or create orders.
- The server-reserved reload marker is boolean-only; no attempt ID, payment selection, expiry or other reservation internals are exposed to the browser, and the browser guard never releases the reservation or creates an order.
- The ambiguity UI guard is defense in depth only and never sends finalization release, submits payment or creates orders; the DB reservation remains authoritative.
- Browser-side release is intentionally forbidden once native payment activation may have started; bounded temporary retry blocking is preferred over reopening a duplicate native handoff.
- Reservation release remains exact customer/attempt scoped; uncertain Core order state preserves the duplicate-handoff barrier instead of weakening it.
- The HTTP contract does not create carts/orders or call payment order-creation APIs; it only checks external fail-closed behavior.
- No module version bump: test-runner/runtime-test coverage, eager integration fallback, reservation policy, effective DI TTL alignment, locked/live/reload UI safety, payment-handoff browser safety and documentation changes introduce no new schema/config/hook migration.

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

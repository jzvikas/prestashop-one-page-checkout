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
- `CheckoutFinalizationReservationRecoveryContractSmokeTest.php` and ADR-0022 documenting the hardened duplicate-handoff recovery window, cart-level ownership barrier and order-aware release rule.
- Final-submit browser contract coverage for the post-activation fail-closed boundary on ordinary and binary payment handoffs.
- `ordinary-payment-submit-guard.js` to block observable direct submission of selected ordinary Core-presented payment forms before OPC finalization preflight/reservation.
- `CheckoutOrdinaryPaymentSubmitGuardContractSmokeTest.php` and ADR-0023 documenting the exact-form, one-shot ordinary payment handoff authorization boundary.
- `payment-handoff-ambiguity-guard.js` to lock a checkout after reload when the server still owns an active finalization reservation, after `finalization_in_progress`, or after an ambiguous post-activation payment-handler failure.
- `CheckoutFinalizationReservationBrowserGuardContractSmokeTest.php` and ADR-0024 documenting reload-safe reservation UX, shared ambiguity lifecycle and the binary post-activation `AbortError` rule.
- Request-local native-checkout fallback containment with eager shell preparation before version-specific process takeover.
- `IntegrationFailureIsolationContract.php` in every installed PrestaShop 9.0/9.1/9.2 runtime job, plus source smoke contracts and ADR-0025/ADR-0026 documenting the containment and installed-object identity gate.
- Disposable `/tmp/jzopc-active-fixture*` runtime builder that can open readiness only in the copied test module while rechecking production source remains closed.
- Controlled active HTTP fallback matrix for persistence, shell-service, real Smarty-template and asset-registration failures, with same-session recovery and cleanup.
- Pinned Playwright/Chromium active checkout contract covering real Core cart creation, OPC root/bootstrap/assets, server identity validation, native fallback and same-cart recovery.
- `finalization-preflight-browser-contract.mjs` covering a real active-browser premature finalization attempt with valid CSRF/cart/state bindings, requiring `customer_required` and proving no reservation is leaked after rejection.
- ADR-0027 and ADR-0028 documenting the isolated active HTTP fixture and controlled Chromium takeover/finalization-preflight contracts.
- `FinalizationReservationMariaDbContract.php` as a PrestaShop 9.1.5-only installed MariaDB gate for real reservation-table acquisition, exact-attempt idempotency, competing-attempt rejection, customer-transition ownership, exact release and database-time expiry replacement.
- `CheckoutFinalizationReservationMariaDbRuntimeContractSmokeTest.php` and ADR-0029 locking the 9.1.5 runtime wiring and documenting that this database gate does not replace the still-required concurrent-tab/payment-module browser matrix.
- `FinalizationReservationConcurrencyMariaDbContract.php` as a PrestaShop 9.1.5-only process-level MariaDB contention gate using independent PHP processes/connections and a two-phase ready/start barrier to exercise simultaneous production-store acquisition.
- `CheckoutFinalizationReservationConcurrencyMariaDbRuntimeContractSmokeTest.php` and ADR-0030 locking the 9.1.5 process-concurrency wiring, exact-idempotent replay and same-cart cross-customer contention expectations.
- `finalization-concurrent-tabs-preflight-browser-contract.mjs` and ADR-0031 locking the 9.1.5 two-tab preflight-before-reservation invariant for an intentionally incomplete checkout.
- `CheckoutFinalizationReservationUnavailable` and ADR-0032 defining an explicit fail-closed domain boundary for ambiguous reservation reads, writes and releases.

### Changed

- Finalization reservation default TTL increased from 90 seconds to 900 seconds, with constructor overrides bounded to 60..3600 seconds, so slow redirect/payment initialization cannot reopen the handoff barrier prematurely.
- An unexpired finalization reservation is now treated as a shop/cart-level payment-handoff barrier before customer comparison. A stale tab or customer-binding transition can no longer delete the active row merely because its current customer ID differs; only the same customer/state/payment/attempt is idempotent, while competing identity/attempt traffic fails closed until exact release, Core order cleanup or TTL expiry.
- Expired reservation cleanup now rechecks `expires_at <= UNIX_TIMESTAMP()` in the exact shop/cart `DELETE` and re-reads the barrier when that conditional delete loses a race, preventing a stale reader from deleting or bypassing a concurrently created fresh reservation.
- Attempt-scoped reservation release now combines exact shop/cart/customer/attempt matching and Core-order absence in one SQL `DELETE ... NOT EXISTS` statement, removing the previous order-check/delete TOCTOU window while remaining fail-closed on database failure.
- Reservation persistence uncertainty is now classified explicitly: ambiguous acquisition/read/release failures map to `CheckoutFinalizationReservationUnavailable`, and finalization returns stable `finalization_unavailable` failure instead of ever treating an unproven reservation outcome as successful handoff or recovery.
- Ordinary and binary payment adapters no longer automatically release a reservation after module-owned native activation has begun. If a third-party handler throws after submit/click invocation starts, checkout remains frozen behind the reservation until Core successful-order cleanup or bounded TTL recovery.
- Binary payment preflight publishes `jzopc:checkout:validation-failed`, and `AbortError` is considered a harmless abort only before native click/form activation starts; the same error name after activation is treated as ambiguous and preserves the reservation.
- A server-rendered active finalization reservation locks the checkout after page reload; the same lock is entered after a `finalization_in_progress` server rejection and suppresses link-style as well as form-style payment activation.
- Ordinary payment form controls remain enabled and untouched for native successful controls/embedded integrations, but a capture-phase guard blocks normal direct submit before the reserved final-submit handoff. Authorization is exact option/form scoped, consumed by the first observable submit and revoked after the current synchronous handoff stack or payment/section change.
- Checkout process construction pre-renders the complete shell before Core process/provider takeover. Legacy process reference assignment happens only after replacement construction succeeds, and the 9.2 provider receives already-prepared shell HTML.
- Checkout hook/asset integration failures trip a request-local circuit breaker, causing later takeover decisions in the same request to leave Core native checkout intact. Fallback logging excludes exception messages and request/payment/customer payloads.
- Runtime shop domain is pinned to `localhost:8080` so generated checkout endpoints, loopback server and Chromium origin agree exactly.
- Installed runtime workflow now proves closed production behavior first, stops that server, then remounts only the disposable active fixture for browser/failure testing.
- The active runtime browser phase now runs the takeover/fallback contract and separate finalization-preflight/concurrent-tab preflight rejection contracts before HTTP fixture cleanup.
- The installed PrestaShop 9.1.5 runtime job now contains dedicated sequential and process-concurrent production-store/MariaDB reservation contracts before the broader Core process/browser gates; no equivalent 9.2 requirement is added to the current 9.1.5 production milestone.

### Verification

- CI #113 on `239f2ad61a802d351ca92e8106bad4c7a1c5d0bb` completed successfully through Composer metadata, PHP syntax, JavaScript syntax and the full smoke suite.
- PrestaShop Runtime #63 on the same commit completed successfully for 9.0.3, 9.1.5 and 9.2.0-beta.1. Every family passed module installation, Core process adapter, the new integration-failure-isolation contract, installed Smarty shell, module-front CheckoutSession and fail-closed Front Office HTTP checks.
- CI #112 previously verified the reservation-browser hardening source/smoke layer on `852181de9687726521f95de479f3f2f58986ce8b`.
- The atomic reservation-release, cross-customer cart-level reservation, race-safe expiry cleanup, active HTTP/Chromium, finalization-preflight/concurrent-tab Chromium, PrestaShop 9.1.5 sequential/process-concurrency MariaDB reservation-runtime and reservation-storage-uncertainty deltas have not executed in this delta and must not be counted as passing while GitHub Actions quota remains exhausted.
- Representative real payment completion, carrier diversity, fully orderable concurrent-tab/customer-binding finalization, zero-total order completion and full account/address browser flows remain release blockers.

### Safety

- `INTEGRATION_SHELL_READY` remains `false` in repository production source; these changes do not enable production checkout takeover.
- The active runtime builder refuses to run without an explicit env guard and refuses targets outside `/tmp/jzopc-active-fixture*`; it rechecks the source readiness constant before and after patching the disposable copy.
- Runtime failure instrumentation exists only in the temporary copied module and production service/template/asset sources are required to stay marker-free.
- The finalization-preflight browser contracts call only the real guarded `begin` endpoint while checkout identity is intentionally incomplete; they require rejection before reservation acquisition and never cross payment handoff or create an order.
- The 9.1.5 reservation MariaDB contracts use synthetic cart/customer identities only in the module-owned reservation table; the process-concurrency workers call only the production reservation store, never `validateOrder()`, never create a Core `Order`, and never change the production readiness gate.
- Active reservation ownership is fail-closed at shop/cart level: a mismatched customer cannot clear an unexpired handoff barrier, expired-row cleanup cannot erase a concurrently refreshed barrier, explicit release remains exact customer/attempt scoped, Core-order absence is part of the same database statement that removes the barrier, ambiguous reservation persistence is never reported as success, and ambiguous post-activation payment-handler failure still preserves the reservation.
- No module version bump: reservation ownership/release/expiry/storage hardening, browser policy, fallback containment and runtime/browser-test infrastructure introduce no new schema/config/hook migration.

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
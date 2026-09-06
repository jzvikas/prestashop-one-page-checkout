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
- `finalization-orderable-concurrent-tabs-browser-contract.mjs`, `CheckoutOrderableConcurrentTabsBrowserRuntimeContractSmokeTest.php` and ADR-0033 for a fully orderable PrestaShop 9.1.5 two-tab reservation contention gate using a pinned official `ps_checkpayment` option without submitting the payment form.
- `tests/Runtime/prestashop-http-router.php` to preserve normal web-server static-file semantics in the PHP runtime harness while still routing dynamic checkout URLs through the real PrestaShop Front Office entry point.
- `ActiveCoreCarrierAvailabilityContract.php`, its source smoke contract and ADR-0035 to prove the disposable 9.0/9.1 carrier fixture survives Core zone/group/shop/product discovery before any Chromium checkout mutation is trusted.
- `ActiveCartDeliveryStateContract.php`, its source smoke assertions and ADR-0036 to diagnose the exact browser-created 9.1.5 Core cart after an orderable Chromium failure without mutating carrier/payment/order state.
- ADR-0037 documenting the fail-closed authoritative-cart refresh boundary after native Core address persistence.
- ADR-0039 documenting the PrestaShop 9.1 `--fixtures=0` payment/carrier restriction boundary and the fixture-only Core `module_carrier` repair required when the deterministic carrier is created after official `ps_checkpayment` installation.
- ADR-0040 documenting that disposable standalone runtime fixture failures must remain fail-fast instead of invoking container-dependent `Carrier::delete()` cleanup that can mask the original error on PrestaShop 9.1.
- ADR-0041 documenting the Core carrier-reference reload boundary after `Carrier::add()` so payment restrictions consume the database-persisted reference rather than stale in-memory state.
- `ActiveCheckoutPersistenceFailureControl.php` and ADR-0047 for a fail-closed `/tmp`-only persistence failure control used by the real Chromium checkout session without exporting browser cookies, tokens or customer payloads to fixture code.
- Browser source contracts requiring persistence/service/template/assets failure fallback, dormant OPC runtime on Core fallback, and exact same-cart recovery before later finalization browser gates run.

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
- The PrestaShop 9.1.5 active-browser phase now pins official `PrestaShop/ps_checkpayment` commit `163eea350e29616f7cff343285d8c4bcc2b6cc44`, enables guest checkout in the disposable runtime shop, prepares identity/address/carrier/payment/agreement state through real browser mutations, and exercises successful reservation acquisition against a competing tab without invoking native payment submission.
- Both fail-closed and active PHP development servers now use the static-aware runtime router so existing module/theme assets are served directly instead of being incorrectly converted into PrestaShop application 404 responses.
- The disposable Core carrier fixture now explicitly persists non-module/free/no-range/no-package-limit flags, and installed 9.0/9.1 runtime verifies `Carrier::getCarriersForOrder()` plus `Carrier::getAvailableCarrierList()` before browser delivery selection.
- The post-browser live-cart diagnostic now lets `Db::getValue()` own scalar limiting and boots the installed Front Kernel before Core delivery-option calculation, removing harness-only duplicate-`LIMIT` and missing-container failures.
- After a successful native Core address save, dependent OPC sections now render from a freshly loaded Core Cart only after verifying the same cart/customer/shop and committed delivery/invoice address bindings; ambiguous refresh fails closed instead of reusing a potentially pre-mutation in-memory cart.
- The disposable 9.1 runtime fixture now adds its post-install deterministic carrier reference to the pinned official `ps_checkpayment` Core carrier restriction and the pre-Chromium runtime contract proves that exact association; production OPC payment discovery and payment-module restrictions remain untouched.
- Standalone active-fixture failure paths no longer invoke `Carrier::delete()` without a Symfony Front Kernel; the disposable runtime database owns failed-fixture cleanup so the first meaningful carrier/payment setup error remains visible.
- The runtime carrier is reloaded through the Core `Carrier` model immediately after `Carrier::add()` because PrestaShop 9.1 persists `id_reference` in a follow-up SQL update without mutating the existing object's property; the payment fixture now consumes the persisted server-authoritative reference.
- Active fallback release evidence now comes from the same Playwright/Chromium Core cart/session used by checkout users. The standalone PHP/cURL fallback harness remains diagnostic history but is no longer a workflow release gate after executed 9.0.3/9.1.5 runs showed a transport-only HTTP 200/zero-body condition after the browser checkout had already rendered successfully.

### Verification

- CI run `34024940433` on `ec604e84d71410c57061ba489c634c4215a60a9d` executed successfully through Composer metadata, PHP syntax, JavaScript syntax and the full smoke suite.
- PrestaShop Runtime run `34024940439` on the same commit executed the 9.1.5 installation, reservation MariaDB/process-concurrency, Core process, isolation, Smarty, fail-closed HTTP, active checkout Chromium, finalization preflight and concurrent-tab preflight gates successfully. Its fully orderable Chromium gate still failed before carrier selection, but the kernel-aware post-browser live-cart diagnostic then passed customer/group/address ownership, country/zone, Core carrier eligibility, physical product and fresh `Cart::getDeliveryOptionList()` assertions. This is executed evidence that the remaining defect boundary is same-request OPC delivery rendering rather than persisted Core cart/carrier eligibility.
- A later installed 9.1.5 runtime on `95260cc839f892df7cc1348da5961b4019021110` executed through real identity/address/carrier preparation and reached payment selection, then failed because Core exposed zero `ps_checkpayment` options. Source review traced this to the runtime-only installation order: `PaymentModule::install()` captured carrier restrictions before the deterministic `--fixtures=0` carrier existed.
- CI run `34031916946` on `9f94ad2ac27a21e20afa5fda3e3111bd4a7ec913` executed successfully through Composer metadata, PHP syntax, JavaScript syntax and the full smoke suite. The same revision's 9.2.0-beta.1 installed runtime completed successfully.
- The executed 9.1.5 job on `9f94ad2ac27a21e20afa5fda3e3111bd4a7ec913` proved ADR-0040 removed the masking `Carrier::delete()` container fatal and exposed the underlying stale-reference error: `Carrier::add()` had persisted a positive reference in SQL while the in-memory carrier still reported zero.
- PrestaShop Runtime run `34053774661` on `aa9b06b0622e9f11ded29a817e99f4ec406aa9d9` executed the active Chromium checkout on both 9.0.3 and 9.1.5. The 9.1.5 job additionally executed the fully orderable two-tab reservation gate successfully (`one winner / one finalization_in_progress loser`, exact replay/release) before the later standalone PHP/cURL fallback harness failed its initial healthy `/order` with HTTP 200 and zero downloaded body bytes. The 9.0.3 row exposed the same late cURL-only condition. These runs are evidence for moving fallback authority to Chromium, not evidence that the new exact-head matrix has passed.
- CI on `978b8a83534f3201ead7037979b3f2632edf95b8` executed PHP/JS syntax successfully and then caught an interpolated `${mode}` smoke-source assertion. The assertion was corrected on later commits; no green claim is made for the browser-authoritative milestone until an exact-head CI/runtime execution completes successfully.
- Representative real payment completion, carrier diversity, successful Core-order cleanup/TTL recovery, zero-total order completion and full account/address browser flows remain release blockers.

### Safety

- `INTEGRATION_SHELL_READY` remains `false` in repository production source; these changes do not enable production checkout takeover.
- The active runtime builder refuses to run without an explicit env guard and refuses targets outside `/tmp/jzopc-active-fixture*`; it rechecks the source readiness constant before and after patching the disposable copy.
- Runtime failure instrumentation exists only in the temporary copied module and production service/template/asset sources are required to stay marker-free.
- Browser persistence failure control accepts only `/tmp/prestashop`, verifies the installed module resolves to `/tmp/jzopc-active-fixture*`, touches only the module selection schema through its schema service, and never receives browser cookies/tokens/customer payloads.
- The browser-authoritative fallback matrix keeps persistence/service/template/assets failures and recoveries inside one Chromium context, requires native Core checkout with dormant OPC runtime during each injected failure, and requires the exact same Core cart ID after each recovery.
- The finalization-preflight browser contracts call only the real guarded `begin` endpoint while checkout identity is intentionally incomplete; they require rejection before reservation acquisition and never cross payment handoff or create an order.
- The orderable concurrent-tab contract uses Core-rendered guest/address forms, Core carrier/payment/agreement selection and the real guarded finalization endpoint; it deliberately stops before the pinned official payment module's native form submission, never calls `validateOrder()`, and verifies that only the exact winning attempt can clear the active reservation.
- The active Core carrier and live-cart delivery probes are CLI-only test infrastructure. They call Core carrier/delivery discovery APIs but do not persist a delivery selection, submit payment, call `validateOrder()` or create a Core order.
- The authoritative-cart address refresh happens only after native Core address persistence, verifies cart/customer/shop and committed role bindings before replacing `Context::cart`, and does not write a carrier selection or create an order.
- The runtime payment/carrier association is confined to the disposable 9.1 test shop and only mirrors PrestaShop's normal `PaymentModule` carrier restriction table for a carrier created after module installation; production OPC never edits third-party payment restrictions or fabricates payment options.
- The runtime carrier reference used for payment restrictions is read back through Core after creation; the harness never fabricates or assigns a carrier reference, and failed standalone fixture preparation never invokes the Symfony-container-dependent carrier deletion path.
- The 9.1.5 reservation MariaDB contracts use synthetic cart/customer identities only in the module-owned reservation table; the process-concurrency workers call only the production reservation store, never `validateOrder()`, never create a Core `Order`, and never change the production readiness gate.
- Active reservation ownership is fail-closed at shop/cart level: a mismatched customer cannot clear an unexpired handoff barrier, expired-row cleanup cannot erase a concurrently refreshed barrier, explicit release remains exact customer/attempt scoped, Core-order absence is part of the same database statement that removes the barrier, ambiguous reservation persistence is never reported as success, and ambiguous post-activation payment-handler failure still preserves the reservation.
- The test-only PHP router rejects traversal-like static paths and only delegates existing GET/HEAD files to the built-in static server; dynamic or missing paths continue through PrestaShop Core. It does not alter production routing or module behavior.
- No module version bump: reservation ownership/release/expiry/storage hardening, browser policy, fallback containment, authoritative-cart refresh and runtime/browser-test infrastructure introduce no new schema/config/hook migration.

## 0.4.0

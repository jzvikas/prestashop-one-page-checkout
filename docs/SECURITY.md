# Security review

This document tracks implemented checkout controls and remaining release-blocking verification. The browser is always untrusted; the loaded PrestaShop `Context`/`Cart` and fresh Core services are authoritative.

## 1. Trust boundary

A submitted cart ID is only a binding assertion and is never used to load another cart. The browser is not authoritative for:

- prices, taxes, discounts, shipping or payable totals;
- customer/cart ownership;
- address ownership;
- carrier or payment eligibility;
- canonical payment/agreement selections;
- required legal conditions;
- final order eligibility.

Passwords, CSRF/auth tokens, cookies/session identifiers, payment secrets and full customer/address payloads must not be logged.

## 2. Activation and endpoint exposure

All checkout mutation controllers are POST-only and inherit the same activation gate used by checkout hooks. `CheckoutActivationPolicy` rejects:

- unsupported runtime capability;
- enabled native `ps_onepagecheckout` provider conflict on the provider path;
- disabled merchant feature flag;
- closed `INTEGRATION_SHELL_READY`.

`INTEGRATION_SHELL_READY` is currently `false`, so custom process takeover, checkout runtime assets and mutation endpoints remain unreachable in normal production checkout traffic.

Required OPC JavaScript is bound to the successfully rendered custom shell rather than to Core's page-level asset queue. `CheckoutShellRenderer` must resolve the complete six-file manifest from PrestaShop's `_MODULE_DIR_` before shell rendering. If manifest resolution or shell preparation fails, provider exposure / legacy process replacement fails closed and Core remains authoritative. Native Core checkout and native-OPC conflict fallback do not render the custom shell and therefore do not receive OPC runtime scripts.

The compatibility `register()` hook boundary no longer calls `FrontController::registerJavascript()`. This deliberately avoids a lifecycle state in PrestaShop 9.0/9.1 where a custom shell could render after the page-level JavaScript queue was already finalized, and also avoids duplicate execution on themes where an early registration could succeed.

The Back Office activation page cannot bypass this gate. It allows writes only for one concrete shop and reruns the same capability/native-conflict/readiness decision before accepting `1`. Group/all-shop contexts do not write activation state.

The installed-runtime active-fallback matrix is browser-authoritative. Persistence, shell-service, template and asset-manifest failures are injected only into the disposable `/tmp/jzopc-active-fixture*` module while one Chromium context retains the real Core cart/session. The persistence control accepts only `/tmp/prestashop`, requires `JZOPC_RUNTIME_ACTIVE_FIXTURE=1`, verifies the installed module resolves to the disposable fixture and touches only the module selection schema. It never receives browser cookies, CSRF tokens, customer payloads or payment data. Every injected failure must render native Core checkout with no OPC root/runtime initialization and then recover the exact same Core cart after cleanup. The older standalone PHP/cURL fallback harness is no longer a release gate because executed 9.0.3/9.1.5 jobs proved Chromium checkout while that separate client reported a transport-only HTTP 200/zero-body condition.

## 3. CSRF and cross-cart/customer binding

`CheckoutMutationGuard` validates the Core front-office token, requires the submitted cart ID to equal the already-loaded server cart and checks context/customer identity when the cart is customer-bound.

Mutation services execute only through `CheckoutMutationOrchestrator`; finalization uses this same or stronger boundary.

The browser reserves `token`, `cartId` and `stateVersion` fields. Serialized theme/module forms cannot overwrite those outer trusted bindings.

The native Core address form has its own persister token; the module regenerates that server-side rather than accepting browser authority for it.

Identity changes may rotate the Core front CSRF token. A replacement token is returned only after the guarded mutation reaches completed status. Rejected requests never receive replacement token material.

## 4. Same-cart races and stale state

Every mutation requires an opaque prior `stateVersion`. `CheckoutCartMutex` serializes same-cart mutations with parameterized DB advisory locking. The full authoritative selection load and stale-state check happen inside that critical section.

Browser transport adds latest-intent-wins behavior:

- monotonic request sequence;
- `AbortController` where available;
- superseded-response rejection even if abort fails;
- one bounded replay after server `stale_state` only;
- complete section-set validation before any DOM write.

Client race protection is an optimization/UX layer, not a substitute for the server lock/state guard.

## 5. Identity/password handling

Guest/account/login business rules are delegated to Core `CustomerForm`, `CustomerPersister` and `CustomerLoginForm` with the Core hashing service and `PS_GUEST_CHECKOUT_ENABLED`.

The module does not hash/store customer passwords itself and does not implement a parallel duplicate-account or authentication policy.

After successful identity mutation, the active Core customer and cart customer must be the same positive ID. Payment/agreement authority is cleared because customer group/rules/eligibility can change.

If Core `PS_CART_FOLLOWING` restores a different cart during login, the old-cart AJAX critical section does not continue against the replacement cart. The response forces a full order-page reload and a new authoritative bootstrap.

## 6. Address IDOR and validation

Saved address IDs and address edit targets are checked with `Customer::customerHasAddress()` against the cart-bound customer before mutation/loading.

Saved selections are applied through Core `CheckoutSession`, preserving cart delivery-address side effects.

Address create/edit delegates to Core `CustomerAddressForm`, `CustomerAddressFormatter` and `CustomerAddressPersister`, preserving country/state validation, required fields, module hooks and historization.

A successful address mutation clears prior payment/agreement authority before downstream sections are regenerated.

## 7. Carrier authorization

Browser carrier IDs/prices/labels are not accepted as authority. `CheckoutCarrierSelectionService`:

- requires a physical cart and valid cart-bound customer/delivery address;
- reauthorizes the delivery address;
- regenerates fresh Core delivery options;
- accepts only an exact canonical option key;
- writes using Core's address-keyed `CheckoutSession::setDeliveryOption()` payload;
- rereads persisted cart delivery state to confirm Core retained the choice.

A real carrier change clears payment/agreement authority. Virtual carts reject carrier mutation.

Finalization revalidates carrier eligibility again immediately before payment handoff.

The disposable installed-runtime shop uses a Core `Carrier` fixture because PrestaShop is installed with `--fixtures=0`. That fixture explicitly persists non-module/free/no-range/no-package-limit semantics and is associated with active zones, customer groups and the concrete runtime shop. Before Chromium is allowed to interpret carrier absence as an OPC address/cart problem, `ActiveCoreCarrierAvailabilityContract.php` independently requires Core `Carrier::getCarriersForOrder()` and `Carrier::getAvailableCarrierList()` to retain that exact carrier for the configured guest group/default country zone and physical fixture product. The probe is CLI-only test infrastructure and never writes a delivery selection.

## 8. Payment tampering and native handoff

Payment selection is parsed as bounded identifiers and checked against a fresh Core `PaymentOptionsFinder::present()` result. Module key, option ID and presented module name must match exactly before a canonical selection can be persisted.

That persisted selection is not final order authorization.

`CheckoutFinalizationPreflightService` regenerates fresh payment eligibility inside the serialized cart critical section immediately before native handoff.

The OPC module does not call `PaymentModule::validateOrder()` for normal third-party payment flows. It resumes the selected module's native path:

- ordinary forms: observable jQuery submit, then `requestSubmit()`, raw submit only as final fallback;
- binary/self-submitting options: capture activation, preflight, then replay the exact original module-owned control/form;
- free order: Core `order-confirmation?free_order=1` and `OrderConfirmationController::checkFreeOrder()`.

Unexpected binary preflight section replacement fails closed to avoid destroying third-party runtime handler/state before replay.

Ordinary Core-presented payment forms have an additional browser barrier. `ordinary-payment-submit-guard.js` listens for native `submit` in capture phase and stops a selected non-binary module form unless the final-submit controller has already completed server preflight/reservation and synchronously crossed the `jzopc:checkout:payment-handoff` boundary. The authorization is tied to the exact selected payment option and exact connected form, consumed by the first observable submit and otherwise revoked in a microtask after the current handoff stack. Payment-option changes and section replacement also revoke it. The guard deliberately leaves the module form fields enabled and untouched so tokenization, embedded fields and native successful controls remain compatible.

The ordinary and binary browser adapters enforce an explicit release boundary. Failures that happen before native payment activation starts may release only their exact reservation attempt. Immediately before invoking the module-owned `submit`/`click` path, the adapter marks handoff as started. If a third-party handler then throws synchronously, the adapter does not release the reservation: the handler may already have initiated remote/payment side effects. Instead the checkout is marked `data-jzopc-handoff-uncertain`, every checkout control is disabled, and recovery is left to Core successful-order cleanup or the bounded reservation TTL.

The direct ordinary-form submit guard is defense in depth around normal browser submission, not a replacement for server authority. Client JavaScript cannot securely police hostile or module code that deliberately invokes low-level submission APIs without an observable submit event. Representative third-party embedded/form integrations therefore remain a mandatory browser verification gate.

This client freeze and submit guard are defense in depth only; the DB reservation remains the cross-tab/process duplicate-handoff authority once a reservation exists.

## 9. Legal-agreement tampering

Required conditions come from Core `ConditionsToApproveFinder`, including shop terms and module `termsAndConditions` contributions.

Submitted agreement IDs are bounded and normalized. Approval succeeds only when the submitted set exactly equals the fresh required set. Finalization repeats the same fresh agreement check before handoff.

## 10. Monetary and orderability tampering

No mutation endpoint accepts browser-calculated totals as authority. State/totals fingerprints come from Core cart calculations.

Final preflight rechecks Core orderability, including:

- existing order for cart;
- cart-bound customer;
- non-empty cart;
- stock/current product availability;
- minimum quantities;
- enabled countries;
- Core minimum-purchase requirement;
- delivery/invoice ownership and Core address validation;
- fresh carrier/payment/agreement eligibility.

Missing expected Core presenter data fails closed rather than assuming the checkout is valid.

## 11. Duplicate/replayed final submission

Duplicate-handoff protection is implemented in source.

After successful final preflight, `CheckoutFinalizationReservationStore` acquires a DB-backed reservation scoped to shop/cart/state/payment plus a cryptographically random browser attempt ID.

Security properties:

- one active competing attempt fails closed;
- an unexpired reservation is a shop/cart-level handoff barrier before current customer identity is compared;
- a stale tab or customer-binding transition cannot delete an active reservation merely because its customer ID differs;
- the same attempt is idempotent only when the stored customer, state version, payment selection and attempt ID all match;
- attempt comparison uses constant-time equality;
- release can delete only the same customer/attempt reservation;
- explicit release includes Core-order absence in the same SQL deletion statement, so an order appearing before that statement's database check preserves the reservation;
- release/database uncertainty fails closed and leaves TTL recovery in control;
- automatic browser release is allowed only before native module-owned payment activation is known to have started;
- direct ordinary module-form submit events are stopped before payment handlers unless the reserved final-submit handoff has just authorized the exact form;
- ordinary form authorization is one-shot and expires after the current synchronous handoff stack even when jQuery does not surface a native submit event;
- a synchronous ordinary/binary handler throw after native activation begins preserves the reservation and freezes checkout instead of reopening submission;
- ordinary checkout mutations are frozen while final handoff is reserved;
- default reservation TTL is 900 seconds, with code-level overrides bounded to 60..3600 seconds and expiry based on database time;
- expired reservation cleanup remains bounded to 100 rows per purge;
- Core order existence is checked before finalization and Core payment/order paths retain their own duplicate protections.

A browser busy flag exists only for UX and is not the duplicate-order security boundary.

The longer default TTL deliberately prefers bounded temporary retry blocking over reopening a second native payment handoff while a slow redirect, payment initialization or out-of-process payment action may still be progressing. The same fail-closed rule applies to customer-binding ambiguity: stale traffic is not authorized to clean up an unexpired cart handoff barrier.

A PrestaShop 9.1.5 fully orderable same-session two-tab Chromium gate prepares guest identity, a Core address, a Core carrier, the pinned official `ps_checkpayment` option and current legal agreements through normal browser mutations, then requires exactly one `begin` attempt to acquire the reservation and the competing attempt to receive `finalization_in_progress`. It also requires exact winning replay to remain idempotent, a foreign/losing release to leave `data-jzopc-finalization-reserved="1"`, and the exact winning release to restore `reserved="0"`. The payment form is deliberately never submitted, so this gate verifies the reservation boundary before native payment activation rather than order creation.

Executed PrestaShop Runtime run `34053774661` on commit `aa9b06b0622e9f11ded29a817e99f4ec406aa9d9` completed that fully orderable 9.1.5 two-tab reservation gate successfully: one tab acquired the reservation, the competing tab was rejected with `finalization_in_progress`, winning replay was idempotent, losing release could not clear the barrier and exact winning release succeeded. The same run's later standalone PHP/cURL fallback diagnostic failed with a transport-only HTTP 200/zero-body response after Chromium had already proved the checkout. ADR-0047 therefore moves active fallback authority into Chromium rather than weakening the checkout gate. Native payment submission, customer-binding transition under an already-active reservation, slow/abandoned payment recovery, thrown/partial third-party handlers, Core-order cleanup and TTL recovery remain mandatory production verification.

## 12. Successful-order and abandoned-state cleanup

`actionValidateOrderAfter` invokes module cleanup only after a real Core order exists for the cart. It removes both canonical selection state and finalization reservation state.

Cleanup exceptions are contained/logged safely and are not allowed to turn an already-created order into a customer-visible payment failure.

Abandoned `jzopc_checkout_selection` rows are bounded opportunistically: one in 64 saves may delete at most 100 rows older than 30 days. The GC touches only the module table and does not inspect/delete Core carts or orders.

Finalization reservations have a separate 15-minute default TTL, bounded override range and expired-row cleanup path. A failed or deliberately omitted post-activation release therefore cannot produce an indefinite lock.

## 13. Rendering/XSS boundaries

Module-owned values are escaped by context. Raw HTML is intentionally limited to server-rendered Core/theme/module boundaries:

- native customer/login forms and module-added customer fields;
- native address form and module-added address fields;
- carrier extra/before/after hook HTML;
- payment top/additional-information/forms;
- Core-formatted legal-condition HTML;
- already-rendered trusted section fragments when composing the shell.

Required checkout runtime URLs are internal paths derived from PrestaShop's `_MODULE_DIR_`, not browser input, and are HTML-escaped before being emitted as external deferred `<script src>` attributes. No inline executable JavaScript is introduced by the shell-owned delivery mechanism.

Browser request strings may be passed to Core forms/validators but are never directly concatenated into a new raw HTML boundary.

## 14. SQL/injection

Direct SQL is isolated to infrastructure boundaries:

- advisory locks via Doctrine DBAL parameters;
- checkout-selection/finalization reservation reads/writes/deletes using parameters;
- install/upgrade/uninstall DDL built only from validated PrestaShop DB prefix/engine identifiers;
- abandoned selection/reservation GC uses only internal numeric constants and validated table names.

Browser values are not interpolated into SQL identifiers or statements.

## 15. Back Office rollout security

`BackOffice\CheckoutActivationConfigurationPage` uses standard `HelperForm` and `AdminModules` token/link handling.

Additional safety controls:

- exact single-shop context required;
- exact scalar `0`/`1` activation input;
- explicit shop-group/shop IDs passed to `Configuration::updateValue()`;
- enabling reruns shared activation policy server-side;
- unsupported runtime, native provider conflict or closed readiness gate rejects the write;
- disabling remains possible for the selected shop;
- no all-shop/group rollout write is available from this page.

The internal readiness constant remains private production authority; the BO page only receives its value and cannot change it.

## 16. Threat status

| Threat | Implemented source status | Remaining release requirement |
| --- | --- | --- |
| CSRF | Shared guard; identity rotation only after guarded completion; Core address token regenerated server-side | Execute broader real identity/address/browser cases |
| Cross-cart takeover | Submitted cart is binding-only; loaded cart/context authoritative | Keep same rule for future endpoints |
| Auth-driven cart replacement | Old-cart continuation blocked; full reload establishes replacement cart | Execute `PS_CART_FOLLOWING` browser matrix |
| Password handling | Core persister/hashing owns passwords | Verify password-policy failures and module-added fields |
| Address IDOR | Ownership checked for saved/edit targets | Execute foreign-address browser/runtime tests |
| Forged carrier | Fresh Core option validation + Core persistence + final recheck; active runtime independently gates Core fixture discovery before Chromium | Representative module-carrier/no-carrier browser matrix |
| Forged payment | Fresh Core selection validation + final recheck | Representative redirect/embedded/binary modules |
| Direct ordinary payment-form submit | Capture-phase exact-form barrier; one-shot authorization only after reserved handoff | Browser-test visible submit, Enter key, jQuery/native handlers and embedded/tokenization modules |
| Forged/missing agreements | Exact fresh Core condition-set validation + final recheck | Real TOS/module condition browser matrix |
| Monetary tampering | Server-only totals/orderability inputs | Live cart/promotion/tax scenarios |
| Stale AJAX | Server state guard + cart mutex + browser sequence/abort | Rapid-change browser matrix |
| Missing checkout safety runtime | Shell-owned six-file manifest; unresolved manifest fails before takeover; native fallback receives no OPC runtime | Keep 9.0/9.1 Chromium asset/lifecycle and new four-mode fallback matrix green |
| Concurrent final submission | Cart-level DB reservation; mismatched customer cannot erase active barrier; exact attempt release + atomic Core-order predicate + bounded 15-minute TTL; executed 9.1.5 orderable same-session two-tab gate proved one winner/one blocked competitor and exact replay/release | Verify customer transitions, native payment completion, Core cleanup and TTL recovery |
| Payment/order handoff | Native ordinary/binary/free-order paths + direct ordinary submit barrier + post-activation fail-closed reservation preservation | Real third-party module browser verification, especially embedded forms, thrown/partial handlers and TTL/Core cleanup recovery |

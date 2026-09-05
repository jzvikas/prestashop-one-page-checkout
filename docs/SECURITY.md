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

`INTEGRATION_SHELL_READY` is currently `false`, so custom process takeover, checkout assets and mutation endpoints remain unreachable in normal production checkout traffic.

The Back Office activation page cannot bypass this gate. It allows writes only for one concrete shop and reruns the same capability/native-conflict/readiness decision before accepting `1`. Group/all-shop contexts do not write activation state.

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

Once a finalization reservation exists, ordinary checkout mutations are rejected under the same cart lock with the stable non-retryable `finalization_in_progress` machine code. Competing finalization attempts use the same code after the reservation store rejects a different active attempt. Generic mutations plus ordinary and binary final-submit adapters now publish the same validation lifecycle, and the browser ambiguity guard consumes only that exact code to converge the losing tab to the locked reservation state after controller cleanup. This is defense in depth: the DB reservation remains the security boundary.

Client race protection and browser locking are UX/defense-in-depth layers, not substitutes for the server lock/state/reservation guards.

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

## 8. Payment tampering and native handoff

Payment selection is parsed as bounded identifiers and checked against a fresh Core `PaymentOptionsFinder::present()` result. Module key, option ID and presented module name must match exactly before a canonical selection can be persisted.

That persisted selection is not final order authorization.

`CheckoutFinalizationPreflightService` regenerates fresh payment eligibility inside the serialized cart critical section immediately before native handoff.

The OPC module does not call `PaymentModule::validateOrder()` for normal third-party payment flows. It resumes the selected module's native path:

- ordinary forms: observable jQuery submit, then `requestSubmit()`, raw submit only as final fallback;
- binary/self-submitting options: capture activation, preflight, then replay the exact original module-owned control/form;
- free order: Core `order-confirmation?free_order=1` and `OrderConfirmationController::checkFreeOrder()`.

Unexpected binary preflight section replacement fails closed to avoid destroying third-party runtime handler/state before replay.

Native activation is also a fail-closed recovery boundary. Before a module-owned native submit/click/form replay starts, an exact attempt may still be best-effort released. Once native activation starts, a thrown JavaScript exception is treated as ambiguous because the payment handler may already have started network/order work. Ordinary and binary adapters preserve the reservation, emit `jzopc:checkout:payment-handoff-ambiguous`, and leave recovery to successful Core cleanup or bounded TTL expiry instead of reopening a second handoff.

A reservation can also become known to a browser after page render. Initial reload/back rendering receives only a server-derived boolean reserved marker. If another tab acquires the reservation later, guarded operations return `finalization_in_progress`; all browser mutation/final-submit surfaces now converge on that exact machine code and lock locally without polling, releasing the reservation or submitting payment.

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
- the same attempt may be recognized idempotently;
- attempt comparison uses constant-time equality;
- release can delete only the same customer/attempt reservation;
- explicit release first asks Core whether an order already exists for the cart and preserves the reservation when it does;
- if Core order state cannot be read reliably, release fails closed and leaves TTL recovery in control;
- ordinary checkout mutations are frozen while final handoff is reserved;
- competing tabs and later ordinary mutations receive the same `finalization_in_progress` machine code and now converge to a visibly locked browser state without weakening the DB barrier;
- browser adapters release only before native activation is known to have started; post-activation exceptions preserve the barrier because native progress is ambiguous;
- initial reload/back rendering exposes only a boolean active-reservation marker, never attempt/payment/expiry internals;
- default and installed service wiring use a 900-second reservation TTL, with code-level overrides bounded to 60..3600 seconds and expiry based on database time;
- expired reservation cleanup remains bounded to 100 rows per purge;
- Core order existence is checked before finalization and Core payment/order paths retain their own duplicate protections.

A browser busy flag or local reserved marker is only UX/defense in depth and is not the duplicate-order security boundary.

The longer default TTL deliberately prefers bounded temporary retry blocking over reopening a second native payment handoff while a slow redirect, payment initialization or out-of-process payment action may still be progressing.

Real concurrent-tab/browser verification is still required before this control is considered production-proven. Thrown/partial third-party handler behavior and live losing-tab convergence now have fail-closed source rules, but representative real payment modules must still prove that post-activation errors cannot reopen a second handoff, that a second pre-opened tab locks on conflict, and that abandoned attempts recover through Core cleanup or TTL as designed.

## 12. Successful-order and abandoned-state cleanup

`actionValidateOrderAfter` invokes module cleanup only after a real Core order exists for the cart. It removes both canonical selection state and finalization reservation state.

Cleanup exceptions are contained/logged safely and are not allowed to turn an already-created order into a customer-visible payment failure.

Abandoned `jzopc_checkout_selection` rows are bounded opportunistically: one in 64 saves may delete at most 100 rows older than 30 days. The GC touches only the module table and does not inspect/delete Core carts or orders.

Finalization reservations have a separate 15-minute effective default TTL, bounded override range and expired-row cleanup path. A failed explicit release, competing tab or ambiguous post-activation handoff therefore cannot produce an indefinite lock.

## 13. Rendering/XSS boundaries

Module-owned values are escaped by context. Raw HTML is intentionally limited to server-rendered Core/theme/module boundaries:

- native customer/login forms and module-added customer fields;
- native address form and module-added address fields;
- carrier extra/before/after hook HTML;
- payment top/additional-information/forms;
- Core-formatted legal-condition HTML;
- already-rendered trusted section fragments when composing the shell.

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
| CSRF | Shared guard; identity rotation only after guarded completion; Core address token regenerated server-side | Execute real identity/address/browser cases |
| Cross-cart takeover | Submitted cart is binding-only; loaded cart/context authoritative | Keep same rule for future endpoints |
| Auth-driven cart replacement | Old-cart continuation blocked; full reload establishes replacement cart | Execute `PS_CART_FOLLOWING` browser matrix |
| Password handling | Core persister/hashing owns passwords | Verify password-policy failures and module-added fields |
| Address IDOR | Ownership checked for saved/edit targets | Execute foreign-address browser/runtime tests |
| Forged carrier | Fresh Core option validation + Core persistence + final recheck | Representative/no-carrier browser matrix |
| Forged payment | Fresh Core selection validation + final recheck | Representative redirect/embedded/binary modules |
| Forged/missing agreements | Exact fresh Core condition-set validation + final recheck | Real TOS/module condition browser matrix |
| Monetary tampering | Server-only totals/orderability inputs | Live cart/promotion/tax scenarios |
| Stale AJAX | Server state guard + cart mutex + browser sequence/abort | Rapid-change browser matrix |
| Concurrent final submission | DB reservation + attempt scoping + Core-order-aware release + effective 15-minute TTL + reload/live losing-tab UI convergence | Real concurrent-tab/process, reload/back and slow-payment verification |
| Payment/order handoff | Native ordinary/binary/free-order paths; post-activation exceptions preserve reservation | Real third-party module browser verification of redirect/embedded/binary and thrown/partial handlers |
| Persisted stale selection rows | Immediate order cleanup + bounded abandoned GC implemented | Execute lifecycle/GC/runtime verification |
| Native OPC conflict | Shared policy blocks enabled `ps_onepagecheckout` provider | Re-run 9.2 installed/browser conflict matrix |
| Multistore activation spillover | BO writes limited to exact shop scope | Real multistore BO verification |
| XSS | Escaped normal values; explicit raw Core/theme/module boundaries | Theme/module compatibility testing |
| SQL injection | DBAL parameters + validated identifiers/constants | Preserve isolation for future SQL |

## 17. Logging rules

Server logs may include operation name plus non-sensitive shop/cart identifiers and machine error codes. Do not log:

- passwords;
- payment secrets/credentials/form payloads;
- CSRF/auth tokens;
- cookies/session identifiers;
- full customer/address payloads or unnecessary PII.

Browser lifecycle events must likewise avoid tokens and form payloads. The handoff-ambiguity event contains only the selected payment option identifier already present in rendered checkout state; validation-failure convergence carries the server error list/state version already used by the normal checkout response contract and does not add payment credentials or reservation internals.

## 18. Verification state and release blockers

The source contains final validation, duplicate-handoff barrier, native payment handoff, post-activation ambiguity protection, active-reservation reload/live-tab UI convergence, successful-order cleanup, abandoned-state cleanup and Back Office rollout controls. The reservation recovery boundary uses a payment-safe effective 15-minute TTL, refuses explicit release after a Core order or when Core order state is unknown, and refuses browser automatic release after native payment activation may have begun.

They are still not production-proven. GitHub Actions quota is exhausted, so the latest PHP/Node/smoke/installed-runtime contracts, including the configured PrestaShop 9.0.3 job, reservation-recovery contracts and concurrent finalization UI convergence contract, have not executed.

Before `INTEGRATION_SHELL_READY` can be reconsidered:

1. execute all deferred checks and fix every failure;
2. execute the configured PrestaShop 9.0/9.1/9.2 installed-runtime matrix;
3. prove native fallback/takeover, identity, CSRF rotation/cart restoration and address flows in a browser;
4. prove carrier/no-carrier and representative payment module compatibility;
5. prove zero-total free order, concurrent-tab reservation including losing-tab live/reload convergence, slow/failed/abandoned payment recovery, thrown/partial native-handler behavior and successful cleanup;
6. complete responsive/accessibility/performance and final packaging/release review.

Until then, production checkout takeover remains intentionally disabled.

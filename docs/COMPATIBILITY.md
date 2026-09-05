# Compatibility

This matrix records what the repository currently implements and what has actually been verified. A code path listed here is not considered production-verified until the corresponding runtime/browser gate has executed successfully.

## Platform

| Area | Target / status |
| --- | --- |
| PrestaShop | 9.x (`>=9.0 <10.0`) |
| PHP | 8.4+ |
| Database | PrestaShop-supported MySQL/MariaDB; module runtime DML uses Doctrine DBAL |
| Multistore | Architecture and BO activation are shop-scoped; final browser/runtime rollout still pending |
| Multilingual | Customer-facing/admin strings use PrestaShop translation APIs; runtime matrix still pending for latest delta |

## Checkout integration by PrestaShop version

### PrestaShop 9.0 / 9.1

The module uses `actionCheckoutRender`. Core has already built the native `CheckoutProcess` when this hook executes. `LegacyCheckoutRenderAdapter` reuses the exact Core `CheckoutSession`, prepares the complete OPC shell/replacement process first, and only then assigns the replacement to the reference-bearing hook parameter. If persistence/template/presenter/third-party shell rendering throws, the assignment never happens and Core's original process remains intact.

The installed-runtime workflow contains an explicit PrestaShop 9.0.3 job as family `9.0`, alongside 9.1.5. All installed runtime contracts explicitly accept 9.0/9.1 as the legacy checkout-render family. The new 9.0.3 job has not yet executed because GitHub Actions quota remains exhausted, so PrestaShop 9.0 compatibility is configured but not runtime-verified.

PrestaShop 9.1.5 installed-runtime capability/process coverage existed before the latest identity/address/carrier/finalization/fallback deltas; those newer changes still require a fresh run.

### PrestaShop 9.2+

The module uses `actionCheckoutBuildProcess` only when the provider interface and hook are present. The 9.2-only provider class is isolated so older 9.x versions do not resolve it.

Before the module returns a valid provider it eagerly prepares the OPC shell. If that preparation fails, the hook returns `null`; Core's `CheckoutProcessProviderResolver` therefore has no valid module provider and `OrderController` builds native checkout. Once a provider has been returned, its later `buildCheckoutProcess()` consumes the prepared shell while still using the exact `CheckoutSession` and translator supplied by Core.

An enabled native `ps_onepagecheckout` provider blocks this module's takeover. Core fallback remains untouched when no unique custom provider is active.

The repository previously exercised installed-runtime capability/process behavior on PrestaShop 9.2.0-beta.1, including native-provider conflict detection. The latest checkout/finalization/failure-containment deltas still require a fresh runtime/browser run.

## Installed runtime contract baseline

The current installed module contract requires module version `>=0.4.0`, matching the finalization-reservation schema baseline, and verifies both frontend media registration and the `actionValidateOrderAfter` successful-order cleanup hook. A source smoke contract locks the 9.0/9.1/9.2 workflow-family matrix so future version/test drift is caught before runtime evidence is interpreted.

`CheckoutIntegrationFailureContainmentContractSmokeTest.php` additionally records the source-level native-fallback contract. It does not replace installed failure injection.

These source checks are not a substitute for executing the installed matrix.

## Themes

| Theme category | Source/runtime state |
| --- | --- |
| Classic | Module-owned namespaced checkout shell; native Core customer/address forms preserved. Live browser matrix pending. |
| Hummingbird | Runtime workflow builds theme assets and uses the Core/theme form contracts. Latest browser matrix pending. |
| Third-party themes | No Bootstrap/theme-specific checkout override is required, but real compatibility must be verified per theme. |

Raw HTML is restricted to explicit PrestaShop/Core/theme/module-rendered boundaries such as native identity/address forms, carrier hooks, payment forms/additional information and legal-condition HTML.

Shell composition now happens before process takeover, but no rendered third-party content is cached across requests.

## Payments

Implemented architecture:

- discovery through Core `PaymentOptionsFinder::present()` and `actionPresentPaymentOptions`;
- exact fresh payment-option/module validation before persisting selection authority;
- ordinary payment form handoff using observable submit lifecycle;
- binary/self-submitting handoff through Core's `data-module-name` / `.js-payment-{module}` convention;
- zero-total orders delegated to Core `free_order` / `OrderConfirmationController`;
- final preflight and DB-backed duplicate-handoff reservation before native payment control resumes;
- finalization reservation uses an effective 15-minute database-time recovery window in both store defaults and installed DI wiring, with code-level overrides bounded to 60..3600 seconds;
- explicit attempt release remains customer/attempt scoped and refuses to clear the barrier if Core already has an order for the cart or Core order state cannot be determined safely;
- post-native-activation JavaScript exceptions preserve the reservation rather than assuming release is safe;
- reload/back navigation receives a server-derived boolean reservation marker and immediately presents the fail-closed locked checkout state while the reservation is active;
- tabs rendered before the reservation was acquired converge live when the guarded server returns `finalization_in_progress`: generic mutations and ordinary/binary final-submit paths publish the same validation lifecycle, and the losing tab locks without polling or releasing the reservation;
- once locked, native form controls are disabled and capture-phase `click`/`submit` suppression also covers link-style (`a[href]`) and ARIA-button third-party payment activators without touching unlocked payment hooks/forms.

Still requiring real browser verification:

- representative redirect payment module;
- representative embedded/form payment module;
- module with additional information and JavaScript reinitialization;
- binary click and binary form-submit paths, including link-style activation controls;
- thrown/partial third-party native handlers, including proof that automatic release cannot reopen a handoff already in progress;
- two pre-opened tabs racing finalization plus older-tab mutation attempts after another tab acquires the reservation;
- reload/back behavior while a reservation is active, after successful Core cleanup and after TTL expiry;
- locked-state suppression proving link/form activators cannot reach module handlers while ordinary/binary controls remain fully usable before lock;
- payment failure/retry and abandoned-reservation recovery, including retry after TTL expiry;
- zero-total free order and duplicate refresh behavior.

## Carriers

Implemented architecture:

- Core `CheckoutSession` delivery options;
- `actionCarrierProcess`, `displayCarrierExtraContent`, `displayBeforeCarrier`, `displayAfterCarrier` preserved;
- submitted delivery option validated against a fresh Core set;
- Core address-keyed delivery-option persistence;
- virtual carts reject carrier mutation and omit the delivery section;
- final preflight revalidates the persisted carrier.

Still requiring real browser verification:

- representative module carrier;
- free/paid carrier transitions;
- no-carrier state;
- carrier becoming unavailable after address/cart changes.

## Activation and native fallback

`JZOPC_CHECKOUT_ENABLED` is a shop-scoped merchant setting. The Back Office page accepts writes only in a concrete single-shop context. Enabling is rejected unless runtime capability, native-provider conflict and the internal readiness gate all allow takeover.

When the module is eligible for takeover, integration failure is request-contained: required asset registration failure trips a request-local circuit breaker; shell composition is completed before provider exposure or legacy process assignment; failures fall back to Core native checkout rather than deliberately constructing a partial OPC process. Fallback logging excludes exception messages and request/payment payloads.

`INTEGRATION_SHELL_READY` is currently `false`, so production checkout takeover remains intentionally disabled even though the underlying code paths exist. This is the decisive safety gate until the deferred installed-runtime/browser matrix succeeds.

Real failure-containment verification must inject asset, DB/persistence, template and renderer/service failures on both the 9.0/9.1 legacy and 9.2+ provider families and prove Core native `/order` remains functional.

## Verification limitation

GitHub Actions execution is currently blocked by exhausted repository Actions quota. The PrestaShop 9.0.3 matrix job, reservation-recovery contracts, live concurrent-tab convergence/locked-activation contracts, integration-failure containment contract and updated PHP/runtime/smoke contracts are committed but unexecuted and therefore are not described as passing. The current connected-repository environment also does not provide a local installed PrestaShop/browser runtime.

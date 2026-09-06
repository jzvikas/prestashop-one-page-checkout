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

The module uses `actionCheckoutRender`. `LegacyCheckoutRenderAdapter` receives the Core process by reference, reuses the exact active `CheckoutSession`, and replaces only the checkout process with the module-built process.

The installed-runtime workflow contains an explicit PrestaShop 9.0.3 job as family `9.0`, alongside 9.1.5. All four installed runtime contracts explicitly accept 9.0/9.1 as the legacy checkout-render family. The new 9.0.3 job has not yet executed because GitHub Actions quota remains exhausted, so PrestaShop 9.0 compatibility is configured but not runtime-verified.

PrestaShop 9.1.5 installed-runtime capability/process coverage existed before the latest identity/address/carrier/finalization/BO deltas; those newer changes still require a fresh run. The 9.1.5 job now also contains an unexecuted fully orderable two-tab reservation gate using official `PrestaShop/ps_checkpayment` pinned to commit `163eea350e29616f7cff343285d8c4bcc2b6cc44`.

### PrestaShop 9.2+

The module uses `actionCheckoutBuildProcess` only when the provider interface and hook are present. The 9.2-only provider class is isolated so older 9.x versions do not resolve it.

An enabled native `ps_onepagecheckout` provider blocks this module's takeover. Core fallback remains untouched when no unique custom provider is active.

The repository previously exercised installed-runtime capability/process behavior on PrestaShop 9.2.0-beta.1, including native-provider conflict detection. The latest checkout/finalization/BO deltas still require a fresh runtime/browser run.

## Installed runtime contract baseline

The current installed module contract requires module version `>=0.4.0`, matching the finalization-reservation schema baseline, and verifies both frontend media registration and the `actionValidateOrderAfter` successful-order cleanup hook. A source smoke contract locks the 9.0/9.1/9.2 workflow-family matrix so future version/test drift is caught before runtime evidence is interpreted.

The PrestaShop 9.1.5 runtime additionally wires sequential and process-concurrent MariaDB reservation contracts plus two Chromium contention layers: an intentionally incomplete preflight-before-reservation test and a fully orderable reservation-acquisition test. The latter prepares guest identity, address, carrier, official check-payment selection and current agreements through the active checkout browser surface, but deliberately stops before payment submission.

These source checks are not a substitute for executing the installed matrix.

## Themes

| Theme category | Source/runtime state |
| --- | --- |
| Classic | Module-owned namespaced checkout shell; native Core customer/address forms preserved. Live browser matrix pending. |
| Hummingbird | Runtime workflow builds theme assets and uses the Core/theme form contracts. Latest browser matrix pending. |
| Third-party themes | No Bootstrap/theme-specific checkout override is required, but real compatibility must be verified per theme. |

Raw HTML is restricted to explicit PrestaShop/Core/theme/module-rendered boundaries such as native identity/address forms, carrier hooks, payment forms/additional information and legal-condition HTML.

## Payments

Implemented architecture:

- discovery through Core `PaymentOptionsFinder::present()` and `actionPresentPaymentOptions`;
- exact fresh payment-option/module validation before persisting selection authority;
- ordinary payment form handoff using observable submit lifecycle;
- ordinary Core-presented form fields/markup remain untouched, while capture-phase direct-submit guarding prevents normal user submission before finalization reservation;
- ordinary handoff authorization is exact option/form scoped, one-shot, and revoked after the current synchronous handoff stack or on payment/section change;
- binary/self-submitting handoff through Core's `data-module-name` / `.js-payment-{module}` convention;
- zero-total orders delegated to Core `free_order` / `OrderConfirmationController`;
- final preflight and DB-backed duplicate-handoff reservation before native payment control resumes;
- finalization reservation defaults to a 15-minute database-time recovery window, with code-level overrides bounded to 60..3600 seconds;
- explicit attempt release remains customer/attempt scoped and refuses to clear the barrier if Core already has an order for the cart or Core order state cannot be determined safely.

Configured but not yet executed browser evidence:

- the PrestaShop 9.1.5 job installs official `PrestaShop/ps_checkpayment` at pinned commit `163eea350e29616f7cff343285d8c4bcc2b6cc44` only in the disposable runtime shop;
- the orderable two-tab gate selects that option through normal Core/OPC payment discovery, proves one finalization reservation winner and one `finalization_in_progress` loser, replays the winning attempt idempotently, proves a losing attempt cannot release the active barrier, and then performs the exact winning release;
- the gate never submits the check-payment form and therefore does not claim completed-payment or order-creation compatibility.

Still requiring real browser verification:

- representative redirect payment module through actual native submission/completion;
- representative embedded/form payment module, including visible submit and Enter-key attempts before reservation;
- module with additional information and JavaScript reinitialization;
- jQuery/native ordinary submit handlers and embedded/tokenization form fields through the one-shot authorization boundary;
- binary click and binary form-submit paths;
- thrown/partial third-party native handlers, including proof that automatic release cannot reopen a handoff already in progress;
- payment failure/retry and abandoned-reservation recovery, including retry after TTL expiry;
- successful Core-order cleanup after real module-owned order creation;
- zero-total free order and duplicate refresh behavior.

The ordinary browser guard applies to observable native submit events. It does not claim to make hostile or third-party low-level JavaScript submission authoritative; representative payment-module browser testing remains mandatory.

## Carriers

Implemented architecture:

- Core `CheckoutSession` delivery options;
- `actionCarrierProcess`, `displayCarrierExtraContent`, `displayBeforeCarrier`, `displayAfterCarrier` preserved;
- submitted delivery option validated against a fresh Core set;
- Core address-keyed delivery-option persistence;
- virtual carts reject carrier mutation and omit the delivery section;
- final preflight revalidates the persisted carrier.

The configured PrestaShop 9.1.5 orderable contention gate now requires a real Core delivery option to survive selection and finalization preflight, but it is not a representative third-party carrier compatibility test and has not executed yet.

Still requiring real browser verification:

- representative module carrier;
- free/paid carrier transitions;
- no-carrier state;
- carrier becoming unavailable after address/cart changes.

## Activation and native fallback

`JZOPC_CHECKOUT_ENABLED` is a shop-scoped merchant setting. The Back Office page accepts writes only in a concrete single-shop context. Enabling is rejected unless runtime capability, native-provider conflict and the internal readiness gate all allow takeover.

`INTEGRATION_SHELL_READY` is currently `false`, so production checkout takeover remains intentionally disabled even though the underlying code paths exist. This is the decisive safety gate until the deferred installed-runtime/browser matrix succeeds.

## Verification limitation

GitHub Actions execution is currently blocked by exhausted repository Actions quota. The PrestaShop 9.0.3 matrix job, reservation-recovery contract, ordinary-payment-submit guard contract, orderable concurrent-tab browser gate and updated PHP/runtime/smoke contracts are committed but unexecuted and therefore are not described as passing. The connected repository environment also does not provide a local installed PrestaShop/browser runtime.

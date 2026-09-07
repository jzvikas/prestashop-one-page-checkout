# Compatibility

This matrix records what the repository currently implements and what has actually been verified. A source path is not production-verified until the corresponding installed runtime/browser gate has executed successfully.

## Platform

| Area | Target / status |
| --- | --- |
| PrestaShop | 9.x (`>=9.0 <10.0`) |
| PHP | 8.4+ |
| Database | PrestaShop-supported MySQL/MariaDB; module runtime DML uses Doctrine DBAL |
| Multistore | Architecture and BO activation are shop-scoped; final browser/runtime rollout still pending |
| Multilingual | PrestaShop translation APIs are used; broader runtime matrix still pending |

## Checkout integration by PrestaShop version

### PrestaShop 9.0 / 9.1

The module uses `actionCheckoutRender`. `LegacyCheckoutRenderAdapter` receives Core's active process by reference, reuses the exact active `CheckoutSession`, and replaces only the checkout process with the module-built process.

Installed-runtime coverage has exercised PrestaShop 9.0.3 and 9.1.5 through the disposable active fixture. The browser-authoritative active fallback matrix verifies that persistence, shell-service, template and asset-manifest failures fall back to native Core checkout and recover the same Core cart after cleanup. The 9.1.5 browser matrix also verifies guest identity, Core address/carrier selection, official `ps_checkpayment` discovery, finalization preflight and fully orderable two-tab reservation contention.

The custom shell owns delivery of the six required OPC scripts. Page-level theme asset queue timing is not treated as authoritative for takeover. Native Core fallback does not receive the OPC runtime scripts.

### PrestaShop 9.2+

The module uses `actionCheckoutBuildProcess` only when the provider interface and hook exist. The provider class is isolated so older 9.x versions never resolve the 9.2-only interface.

An enabled native `ps_onepagecheckout` provider blocks this module's takeover. Core fallback remains untouched when no unique custom provider is active.

The configured 9.2.0-beta.1 runtime job verifies the native-OPC conflict/fallback scenario. That job intentionally does not claim active custom-provider payment completion.

## Installed runtime contract baseline

The installed module contract verifies frontend hook registration, persistence schemas and the `actionValidateOrderAfter` successful-order cleanup hook. MariaDB runtime contracts cover sequential and process-concurrent reservation acquisition, exact idempotent replay, competing-attempt rejection, cross-customer cart-barrier behavior, exact release, database-time expiry and bounded recovery.

These source/runtime probes are not substitutes for the remaining representative browser compatibility matrix.

## Themes

| Theme category | Source/runtime state |
| --- | --- |
| Classic | Module-owned namespaced shell; native Core customer/address/payment form boundaries preserved. |
| Hummingbird | Runtime workflow builds PrestaShop front assets and has exercised the active shell/fallback path. |
| Third-party themes | Architecture avoids dependence on one theme's Bootstrap/DOM, but real third-party theme verification remains required. |

Raw HTML remains restricted to explicit PrestaShop/Core/theme/module-rendered boundaries such as native identity/address forms, carrier hooks, payment forms/additional information and legal-condition HTML. OPC runtime URLs are generated from PrestaShop's `_MODULE_DIR_` and escaped before being emitted by the shell.

## Payments

Implemented architecture:

- discovery through Core `PaymentOptionsFinder::present()` and `actionPresentPaymentOptions`;
- exact fresh payment-option/module validation before persisted selection authority;
- ordinary payment form handoff preserving native form controls and observable submit lifecycle;
- capture-phase direct-submit guard for selected ordinary forms before finalization reservation;
- exact option/form-scoped one-shot authorization at the payment-handoff boundary;
- binary/self-submitting handoff through Core's `data-module-name` / `.js-payment-{module}` convention;
- zero-total orders delegated to Core `free_order` / `OrderConfirmationController`;
- final preflight and DB-backed duplicate-handoff reservation before native payment control resumes;
- 15-minute database-time reservation recovery window by default, bounded to 60..3600 seconds;
- exact customer/attempt-scoped release that refuses to clear the barrier when a Core order already exists for the cart.

Executed browser/runtime evidence:

- Native Payment Runtime has completed the official pinned `PrestaShop/ps_checkpayment` path on PrestaShop 9.1.5: Chromium prepares a real orderable checkout through normal OPC mutations, finalization preflight/reservation succeeds, the untouched Core-presented form is handed to the payment module, Core/payment-module code creates exactly one order and `actionValidateOrderAfter` removes both OPC transient rows;
- the real browser direct-submit barrier blocks a Core-presented ordinary payment form before reservation, produces no premature payment validation request, and the same checkout can then complete through normal OPC finalization and the Core-owned payment path;
- ambiguous native handoff preserves the reservation and blocks repeat handoff/final-submit rather than reopening payment after an uncertain module-owned activation;
- the same-cart TTL recovery gate expires an ambiguous reservation using database time, acquires a new finalization attempt, completes through official `ps_checkpayment`, and verifies Core order ownership plus transient-state cleanup;
- the fully orderable two-tab gate verifies one reservation winner, `finalization_in_progress` for the competing attempt, exact idempotent replay, losing-release rejection and exact winning release before native payment activation.

Zero-total runtime status is deliberately not green. Exact-head Native Payment Runtime `34103551955` on commit `45ad3672ce7a319e71432b6e88457ba354894943` reached the canonical Core `POST /order-confirmation?free_order=1`. The request entered Core but did not exit before the bounded Chromium timeout. Failure-only server evidence then found exactly one Core-created order for the cart while both `jzopc_checkout_finalization` and `jzopc_checkout_selection` still contained one row. This proves the current blocker is after Core order persistence and before the free-order request completes; it does not justify OPC-created orders or a synthetic confirmation redirect.

The current exact-head diagnostic delta adds only failure-path, read-only aggregate database process evidence so the next executed run can distinguish an OPC cleanup DELETE waiting on a lock from a stall before cleanup is attempted. Raw SQL/process rows, connection identities and customer/request data are not emitted. No production payment/order/cleanup semantics were changed by that diagnostic delta.

Still requiring real browser verification:

- successful end-to-end zero-total Core free-order completion, confirmation identity/refresh stability and OPC cleanup;
- representative redirect payment module beyond the check-payment fixture;
- representative embedded/tokenization payment form;
- visible submit and Enter-key attempts plus representative jQuery/native third-party handlers;
- payment additional-information JavaScript reinitialization after section refresh;
- binary click and binary form-submit paths;
- broader payment failure/retry variants beyond the already-executed ambiguous-handoff TTL recovery scenario.

The ordinary browser guard is defense in depth around observable submit events. It does not claim authority over hostile low-level submission that deliberately bypasses the native submit event.

## Carriers

Implemented architecture:

- Core `CheckoutSession` delivery options;
- preserved `actionCarrierProcess`, `displayCarrierExtraContent`, `displayBeforeCarrier`, `displayAfterCarrier`;
- submitted option validation against a fresh Core set;
- Core address-keyed delivery-option persistence;
- virtual-cart delivery omission/rejection;
- final carrier eligibility revalidation before payment handoff.

The 9.1.5 active/orderable browser gates require a real Core delivery option to survive selection and finalization preflight. Remaining representative carrier verification includes module carriers, free/paid transitions, no-carrier state and carrier invalidation after address/cart changes.

## Identity and addresses

Installed 9.1.5 active checkout coverage exercises guest creation and Core-native address creation before carrier/payment/finalization. Source contracts retain Core `CustomerForm`, `CustomerPersister`, `CustomerLoginForm`, address ownership checks, `CustomerAddressForm`, `CustomerAddressPersister`, CSRF rotation and `PS_CART_FOLLOWING` cart replacement fail-closed behavior.

Broader account/login, password-policy, foreign-address IDOR and auth-driven cart replacement browser cases remain release gates.

## Activation and native fallback

`JZOPC_CHECKOUT_ENABLED` is shop-scoped. Back Office writes require one concrete shop. Enabling reruns the same capability/native-conflict/readiness policy as frontend takeover.

`INTEGRATION_SHELL_READY` remains `false`, so normal production checkout takeover is intentionally disabled. Disposable `/tmp` runtime fixtures open the gate only for controlled tests.

Integration failures must fail closed to Core checkout. The Chromium fallback matrix, not the older standalone PHP/cURL transport harness, is authoritative for this behavior.

## Verification state

GitHub Actions quota is currently available and exact-head CI/runtime results must be read from executed workflows rather than inferred from source.

The latest completed Native Payment Runtime inspected for the zero-total blocker is `34103551955` on `45ad3672ce7a319e71432b6e88457ba354894943`. All preceding payment/idempotency steps in that run completed successfully; the run failed only at the Core free-order completion Chromium contract. The new post-order database-wait diagnostic is newer than that executed head and must not be described as runtime-verified until its own exact-head workflow executes.

Production readiness remains closed. `INTEGRATION_SHELL_READY=false` must not change until the zero-total blocker and the remaining representative payment/carrier, identity/address, multistore, accessibility/performance and release gates are genuinely executed and green.

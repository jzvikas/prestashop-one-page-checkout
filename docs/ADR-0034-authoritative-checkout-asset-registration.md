# ADR-0034: Required OPC assets must exist before the checkout shell can take over

## Status

Provisional; the new legacy controller-detection fix requires executed Chromium proof.

## Context

The PrestaShop 9 runtime browser matrix exposed a lifecycle mismatch that source-only contracts could not prove. On the legacy 9.0/9.1 checkout path, the custom OPC shell renders with valid server-generated cart, CSRF, state-version and mutation endpoint bindings, but none of the required `jzonepagecheckout` JavaScript assets are present in the document. The browser therefore cannot emit `jzopc:checkout:initialized` and, more importantly, the client-side stale-state, mutation-serialization and payment/final-submit guards are absent.

PrestaShop Core calls `FrontController::setMedia()` before `OrderControllerCore::postProcess()`. `FrontController::setMedia()` then executes `actionFrontControllerSetMedia`, while `OrderControllerCore::postProcess()` later builds the checkout process and executes `actionCheckoutRender`. The rendered JavaScript list is therefore determined from registrations that must already exist before the late checkout-render takeover boundary.

The previous early media hook additionally required `$context->controller instanceof OrderController`. Core's checkout implementation is defined as `OrderControllerCore`, and alias/override exposure is not a safe lifecycle contract to require when deciding whether the current Front Office controller is the checkout page. The hook could therefore return before reaching the registrar even though the same request later reached `actionCheckoutRender` and rendered the OPC shell.

## Decision

`hookActionFrontControllerSetMedia()` now identifies the authoritative Core checkout page through the controller's stable `php_self === 'order'` identity rather than depending on the legacy `OrderController` alias class name. The hook still:

- runs only on the Core order/checkout page;
- requires `isCustomCheckoutActive()` to pass, so feature, runtime-capability, native-OPC conflict and readiness policy remain authoritative;
- obtains the same `CheckoutFrontendAssetRegistrar` service and trips the request-local fail-closed circuit breaker if lookup/registration fails;
- leaves the later provider/legacy keyed registrar calls in place as defensive takeover-boundary assertions.

No checkout mutation, payment handoff or order creation logic changes in this decision. `INTEGRATION_SHELL_READY` remains `false` in production source.

## Prior executed runtime evidence

PrestaShop Runtime run `34013701611` on commit `28f66b4791755c0906f04d8cbbdcc6185b015e10` executed the installed matrix before this fix:

- PrestaShop 9.0.3 passed installation, process, Smarty, fail-closed HTTP and fixture setup, then failed the active Chromium checkout contract because no OPC script loaded;
- PrestaShop 9.1.5 likewise passed the sequential and process-concurrent MariaDB finalization contracts plus the earlier installed checks, then failed the same active Chromium asset assertion;
- PrestaShop 9.2.0-beta.1 completed successfully in its native-OPC conflict/fallback scenario, where active OPC fixture/browser steps are intentionally skipped.

The 9.1 failure diagnostics reported an otherwise valid OPC bootstrap, `scriptSources: []`, `typeof window.JzOpcMutationClient === "undefined"`, and no initialized lifecycle event. That evidence proves the bug was asset delivery rather than shell bootstrap timing.

The earlier attempt to re-register assets from `actionCheckoutRender` was also executed and did not solve the legacy failure; that boundary is too late to be the authoritative registration point.

## Required verification

`tests/Smoke/CheckoutTakeoverAssetRegistrationContractSmokeTest.php` now locks the `php_self === 'order'` early-controller contract and forbids a return to `instanceof OrderController` alias coupling. `tests/Browser/active-checkout-browser-contract.mjs` records asset network responses, script sources, mutation-client availability and lifecycle initialization.

This decision is not considered runtime-verified until a new PrestaShop 9.0/9.1 Chromium run proves that all required OPC JavaScript assets are physically delivered and initialized. If that run fails, the hypothesis must be treated as disproven and the next lifecycle cause investigated rather than weakening the browser gate.

## Invariants

- no custom shell may be considered production-ready unless all required OPC JavaScript can be delivered;
- native/conflicting checkout fallback remains usable without OPC takeover;
- browser state remains non-authoritative; server cart/customer/CSRF/state-version validation and cart mutex semantics remain unchanged;
- the OPC module never creates orders directly and never calls `PaymentModule::validateOrder()`;
- `INTEGRATION_SHELL_READY` remains `false` until this and the final payment-completion gates are genuinely proven.

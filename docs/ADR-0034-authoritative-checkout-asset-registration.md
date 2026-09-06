# ADR-0034: Register required OPC assets at the actual checkout takeover boundary

## Status

Accepted.

## Context

The PrestaShop 9 runtime browser matrix exposed a lifecycle mismatch that source-only contracts could not prove. On the legacy 9.0/9.1 checkout path, the custom OPC shell rendered with valid server-generated cart, CSRF, state-version and mutation endpoint bindings, but none of the required `jzonepagecheckout` JavaScript assets were present in the document. The browser therefore could not emit `jzopc:checkout:initialized`.

The module already attempted conditional asset registration from `actionFrontControllerSetMedia`. That hook executes before the later checkout-process hooks that make the final takeover decision. A request can therefore be undecided or inactive at the early media hook and still become eligible when Core reaches `actionCheckoutRender` / `actionCheckoutBuildProcess`. Depending only on the early hook can expose a custom shell without the JavaScript that enforces stale-state handling, mutation serialization and payment/final-submit guards.

## Decision

Keep `actionFrontControllerSetMedia` as the first registration opportunity, but also register the exact same keyed frontend assets immediately before a successful checkout-process takeover:

- in the PrestaShop provider path, register before shell preparation and before returning `CheckoutProcessProvider`;
- in the legacy path, register before `LegacyCheckoutRenderAdapter` replaces the already-built Core checkout process;
- if the registrar service is unavailable or registration throws, trip the existing request-local integration circuit breaker and do not expose the custom process.

PrestaShop's JavaScript manager keys registrations by asset ID. Re-registering the same six asset IDs is therefore idempotent rather than additive.

The native-OPC conflict scenario remains separate: `isCustomCheckoutActive()` must reject takeover before these authoritative registration calls, so Core/native checkout does not receive OPC JavaScript.

## Safety properties

This closes the state in which an OPC shell can render without its required client guards. It does not make browser state authoritative: cart/customer/CSRF/state-version validation, cart mutex semantics, payment selection and finalization remain server authoritative.

The change does not submit a payment form, create an order or call `PaymentModule::validateOrder()`. Native payment modules/Core continue to own order creation. `INTEGRATION_SHELL_READY` remains `false` until final-submit/payment completion and the remaining runtime/browser release gates are proven.

## Verification

Regression coverage is provided by `tests/Smoke/CheckoutTakeoverAssetRegistrationContractSmokeTest.php` and the active Chromium runtime contract, which verifies all required module scripts are actually returned before accepting `jzopc:checkout:initialized`.

Runtime evidence must be recorded only from executed GitHub Actions results. Source review alone is not treated as a passing browser gate.

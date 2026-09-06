# ADR-0034: Required OPC assets must exist before the checkout shell can take over

## Status

Provisional; legacy runtime gate still failing.

## Context

The PrestaShop 9 runtime browser matrix exposed a lifecycle mismatch that source-only contracts could not prove. On the legacy 9.0/9.1 checkout path, the custom OPC shell renders with valid server-generated cart, CSRF, state-version and mutation endpoint bindings, but none of the required `jzonepagecheckout` JavaScript assets are present in the document. The browser therefore cannot emit `jzopc:checkout:initialized` and, more importantly, the client-side stale-state, mutation-serialization and payment/final-submit guards are absent.

The module originally attempted conditional asset registration from `actionFrontControllerSetMedia`. Core calls `setMedia()` before `OrderController::postProcess()`, while the legacy checkout process is not built/replaced until `postProcess()` executes `actionCheckoutRender`. This ordering is important because PrestaShop's asset list can no longer be assumed to accept registrations late enough for them to appear in the rendered page.

## Attempted hardening

The module now also invokes the same keyed `CheckoutFrontendAssetRegistrar` immediately before successful process takeover:

- in the provider path, before shell preparation and before returning `CheckoutProcessProvider`;
- in the legacy path, before `LegacyCheckoutRenderAdapter` replaces Core's already-built process;
- registrar lookup/registration failures trip the existing request-local circuit breaker rather than exposing a custom process knowingly without its required assets.

PrestaShop keys those registrations by asset ID, so duplicate registration requests themselves are idempotent.

## Executed runtime evidence

The follow-up PrestaShop 9.1.5 Chromium job disproved the assumption that legacy `actionCheckoutRender` registration is early enough. The rendered page still contained the OPC root and complete server bootstrap, while the browser observed:

- no `/modules/jzonepagecheckout/views/js/...` script tags;
- no network response for `payment-controller.js` or the other required OPC scripts;
- `typeof window.JzOpcMutationClient === "undefined"`;
- no `jzopc:checkout:initialized` lifecycle event.

Therefore the late takeover-boundary registration remains useful as a fail-closed assertion but **does not close the legacy runtime bug**. This ADR must not be cited as proof that 9.0/9.1 asset delivery is solved.

Separately, the PrestaShop 9.2 native-OPC conflict runtime scenario is now isolated correctly: it proves Core fallback and skips all active-OPC fixture/browser steps, so the conflict path does not require or inject OPC JavaScript.

## Required next decision

The legacy path needs an earlier authoritative media decision that is compatible with Core's `setMedia()` lifecycle while still refusing assets when native OPC or another incompatible checkout owner blocks takeover. Candidate changes must be proven in the real 9.0/9.1 browser matrix; source contracts alone are insufficient.

Any solution must preserve these invariants:

- no custom shell may render unless all required OPC JavaScript can be delivered;
- native/conflicting checkout fallback must remain usable without OPC takeover;
- browser state remains non-authoritative; server cart/customer/CSRF/state-version validation and cart mutex semantics remain unchanged;
- the OPC module must never create orders directly or call `PaymentModule::validateOrder()`;
- `INTEGRATION_SHELL_READY` remains `false` until this and the final payment-completion gates are genuinely proven.

## Verification

`tests/Smoke/CheckoutTakeoverAssetRegistrationContractSmokeTest.php` locks the current fail-closed registration calls. `tests/Browser/active-checkout-browser-contract.mjs` now records asset network responses, script sources, mutation-client availability and bootstrap data so the runtime matrix can distinguish an asset-delivery failure from a JavaScript timing/bootstrap failure.

Only an executed Chromium run in which all required module assets are actually present and the lifecycle initializes may close this issue.
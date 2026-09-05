# ADR-0025: Native checkout fallback on integration failure

## Status

Accepted for implementation. Production checkout takeover remains disabled by `INTEGRATION_SHELL_READY=false`. The installed/runtime failure-isolation gate is required before this containment path is treated as verified.

## Context

The custom checkout shell depends on module persistence, Core presenters, theme templates and third-party carrier/payment/legal hooks. Those boundaries may throw because of database failure, incompatible module output, template failure or a broken service graph.

Rendering the shell only after Core has already selected the custom checkout process is unsafe. On PrestaShop 9.0/9.1 the native process already exists before `actionCheckoutRender`; leaving that reference untouched is the safest fallback. On PrestaShop 9.2+ Core resolves providers before it builds the selected process; a module must therefore complete risky shell preparation before exposing a valid provider.

Frontend asset registration is another takeover boundary. Core executes `setMedia()` before order-controller checkout bootstrap, so a required asset failure can be remembered request-locally and prevent a later custom-process takeover in the same request.

## Decision

1. `CheckoutProcessBuilder::prepareShell()` renders the complete OPC shell before process takeover and rejects empty output.
2. `CheckoutProcessBuilder::buildPrepared()` constructs the Core `CheckoutProcess` from an already-prepared shell and the exact Core `CheckoutSession` supplied by the active integration path.
3. `CheckoutShellStep` stores only the prepared HTML string. It still renders through Core `AbstractCheckoutStep::renderTemplate()`, preserving `actionCheckoutStepRenderTemplate`, but it no longer invokes risky shell DB/template dependencies after takeover.
4. On PrestaShop 9.0/9.1, `LegacyCheckoutRenderAdapter` constructs the complete replacement process first and assigns the reference-bearing `checkoutProcess` parameter only after that succeeds. A thrown preparation error therefore leaves Core's original process untouched.
5. On PrestaShop 9.2+, `hookActionCheckoutBuildProcess()` calls `prepareShell()` before returning `CheckoutProcessProvider`. If preparation fails, it returns `null`, allowing Core to use its native checkout path.
6. The 9.2+ provider receives the already-prepared shell and later combines it only with the exact Core session/translator supplied by Core.
7. The module owns a request-local `checkoutIntegrationFailed` circuit breaker. Activation-policy, required asset, provider-preparation or legacy-preparation failure marks the request failed, and later custom-checkout activation decisions in the same request return false.
8. Asset registration failure trips that circuit breaker before checkout bootstrap.
9. Fallback logs contain only an internal stage, exception class and numeric shop/cart identifiers. Exception messages, tokens, request/form payloads, payment data and customer data are not logged.
10. Containment does not mutate carts, release finalization reservations, submit payments or create orders.
11. No schema/configuration/hook migration is introduced; module version remains `0.4.0`.

## Compatibility and security rationale

Core native checkout is the recovery target because it already owns the authoritative cart, checkout session and payment/order lifecycle. Eager shell preparation moves risky composition earlier within the same request without caching third-party HTML across requests or replacing Core business logic.

If shell preparation fails after OPC assets were registered successfully, native checkout can still render. OPC JavaScript remains dormant without the module-owned `[data-jzopc-checkout]` root.

## Verification

`CheckoutIntegrationFailureContainmentContractSmokeTest.php` locks the source ordering and request-local circuit-breaker contract. `CheckoutVersionedProcessAdapterContractSmokeTest.php` locks the prepared-shell process/provider shape.

The installed PrestaShop 9.0/9.1/9.2 failure-isolation contract described by ADR-0026 must execute successfully. Controlled active HTTP/Chromium failure injection remains required before `INTEGRATION_SHELL_READY` may be reconsidered.

# ADR-0027: Native checkout fallback on integration failure

## Status

Accepted for source implementation. Installed-runtime/browser verification remains pending and `INTEGRATION_SHELL_READY=false` remains unchanged.

## Context

The custom checkout shell depends on module persistence, Core presenters, theme templates and third-party carrier/payment/legal hooks. Any of those boundaries can throw because of database failure, an incompatible module, template failure or a broken service graph.

Previously the module replaced/exposed its checkout process before `CheckoutShellRenderer::render()` actually executed. The risky shell render happened later inside `CheckoutShellStep::render()`. At that point Core had already selected the custom checkout process, so an exception could escape as a customer-visible HTTP 500 instead of leaving the native checkout available.

The version-specific Core behavior matters:

- PrestaShop 9.0/9.1 builds the native `CheckoutProcess` first and then executes `actionCheckoutRender` with that process by reference. If the module does not replace the reference, native checkout remains intact.
- PrestaShop 9.2+ `CheckoutProcessProviderResolver` executes `actionCheckoutBuildProcess`, filters enabled providers and returns `null` when there is no unique valid provider. `OrderController::buildCheckoutProcess()` then builds the native checkout process. The resolver does not catch an exception thrown later from a selected provider's `buildCheckoutProcess()`.
- Core `Controller::run()` calls `setMedia()` before `postProcess()`. Therefore an asset-registration failure can be remembered request-locally before `OrderController::postProcess()` bootstraps checkout.

These points were rechecked against upstream PrestaShop source at commit `6038dad553035b309f41bd832a6187718312bd03` (`controllers/front/OrderController.php`, `src/Adapter/Order/Checkout/CheckoutProcessProviderResolver.php`, `classes/controller/Controller.php`).

## Decision

1. `CheckoutProcessBuilder::prepareShell()` renders the complete OPC shell before process takeover and rejects empty output.
2. `CheckoutShellStep` receives only the already-prepared HTML string. It no longer invokes `CheckoutShellRenderer` during the later Core render phase.
3. `CheckoutShellStep` still renders through Core `AbstractCheckoutStep::renderTemplate()`, preserving `actionCheckoutStepRenderTemplate` around the module step template.
4. On PrestaShop 9.0/9.1, `LegacyCheckoutRenderAdapter` constructs the complete replacement process before assigning to the reference-bearing `checkoutProcess` hook parameter. Any exception therefore leaves Core's original process untouched.
5. On PrestaShop 9.2+, the module hook calls `prepareShell()` before returning a provider. If preparation throws, the hook returns `null`; Core sees no valid module provider and follows its native process path.
6. The 9.2+ provider receives the prepared shell and uses the exact `CheckoutSession` and translator supplied later by Core when constructing the process.
7. The module owns a request-local `checkoutIntegrationFailed` circuit breaker. Once an activation, asset, provider-preparation or legacy-preparation boundary fails, later takeover decisions in the same request return inactive.
8. Frontend asset registration failures trip the circuit breaker. This is safe because Core calls `setMedia()` before order `postProcess()`/checkout bootstrap.
9. Fallback logging contains only an internal stage name, exception class and numeric shop/cart identifiers. Exception messages, tokens, form payloads and payment/customer data are not logged.
10. The circuit breaker and logging are containment only. They do not mutate carts, clear reservations, submit payments or create orders.

## Security and compatibility rationale

Native checkout is the safest recovery target because Core already owns the authoritative cart, checkout session and payment/order path. Returning a blank custom process or trying to synthesize Core checkout after provider selection would be less reliable and could violate compatibility contracts.

Eager rendering intentionally moves only the risky shell composition earlier within the same request. The prepared process still uses the exact Core `CheckoutSession` passed by the legacy process or 9.2+ provider resolver. Third-party shell content is not cached across requests.

If shell preparation fails after OPC assets were already registered, Core native checkout can still render. The OPC JavaScript remains dormant because it requires the module-owned `[data-jzopc-checkout]` bootstrap root.

## Consequences

- A module DB/template/presenter/hook failure during shell preparation can fail back to Core instead of replacing checkout and then throwing during render.
- Asset service/registration failure prevents later takeover in the same request.
- The 9.2+ path never returns a provider whose shell still needs risky rendering work.
- The legacy path never changes the Core process reference until the replacement is complete.
- No schema, configuration key, hook registration or module-version migration is introduced.

## Verification

`CheckoutIntegrationFailureContainmentContractSmokeTest.php` locks the source contract and `CheckoutVersionedProcessAdapterContractSmokeTest.php` has been updated for eager shell preparation.

The new/updated PHP/smoke/installed-runtime/browser checks have not been executed in this milestone because GitHub Actions free quota remains exhausted and the connected repository environment has no installed PrestaShop/browser runtime. They must not be described as passing evidence.

Before readiness can change, controlled installed-runtime/browser verification must inject at least:

- shell persistence/read failure on the 9.0/9.1 path and prove the original Core process remains active;
- shell persistence/read failure on the 9.2+ provider path and prove Core native checkout is selected;
- template/section-renderer exception on both integration families;
- frontend asset registrar failure and prove the later checkout process remains native;
- normal healthy takeover after a fresh request, proving the circuit breaker is request-local only;
- no fallback path that creates an order, releases a finalization reservation or exposes exception details to the customer.

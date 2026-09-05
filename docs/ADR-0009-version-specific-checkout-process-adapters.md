# ADR-0009: Version-specific checkout process adapters

## Status

Accepted.

## Context

The trusted shell/bootstrap foundation exists, but it is not enough to replace PrestaShop checkout. PrestaShop 9.0/9.1 and 9.2+ expose different integration contracts: 9.0/9.1 pass the mutable Core `CheckoutProcess` through `actionCheckoutRender`, while 9.2+ resolve exactly one enabled `CheckoutProcessProviderInterface` returned by `actionCheckoutBuildProcess` and otherwise fall back to native checkout.

The module must preserve Core checkout session ownership, avoid loading 9.2-only interfaces on older 9.x installations, retain checkout-step hook compatibility and keep the current fail-closed activation policy until runtime/browser integration is proven.

## Decision

1. `CheckoutProcessBuilder` creates a real Core `CheckoutProcess` using the already-created Core `CheckoutSession`. It adds one module-owned `CheckoutShellStep` rather than maintaining a parallel checkout-session implementation.
2. `CheckoutShellStep` extends Core `AbstractCheckoutStep`, stays reachable/current as the single one-page step and renders the trusted shell through `renderTemplate()`. This intentionally preserves Core `actionCheckoutStepRenderTemplate` behavior around the module shell.
3. PrestaShop 9.0/9.1 use `LegacyCheckoutRenderAdapter`. It accepts the reference-bearing `actionCheckoutRender` hook payload, validates that Core supplied a real `CheckoutProcess`, reuses that process's `CheckoutSession`, and replaces only the process object with the module-built process.
4. PrestaShop 9.2+ use `Integration/Provider/CheckoutProcessProvider`. That class implements the exact `PrestaShop\PrestaShop\Adapter\Order\Checkout\CheckoutProcessProviderInterface` contract and delegates process construction to the same builder.
5. The 9.2 provider class lives in a separate autoloaded file and is referenced only after `interface_exists()` succeeds. PrestaShop 9.0/9.1 therefore never need to load or resolve a 9.2-only interface.
6. `actionFrontControllerSetMedia` is registered for existing/new installations. The hook is restricted to `OrderController`, passes the same activation policy and then delegates to `CheckoutFrontendAssetRegistrar`. The JavaScript remains dormant when the module-owned root is absent.
7. The module version becomes `0.3.0` because existing installations require the new hook registration. `upgrade-0.3.0.php` is idempotent and adds only that hook.
8. `INTEGRATION_SHELL_READY` remains `false`. These adapters are implementation plumbing, not evidence that the checkout can safely be enabled in production.

## Compatibility and security consequences

- The original Core `CheckoutSession` remains authoritative on both version paths.
- No browser value chooses the integration strategy or creates a checkout process.
- Multiple-provider fallback on 9.2+ remains Core-owned; the existing activation policy also blocks the known enabled native `ps_onepagecheckout` conflict before this module returns a provider.
- Older 9.x runtimes do not resolve the 9.2-only provider interface.
- Shell rendering still uses the server-authoritative cart/selections/bootstrap boundary from ADR-0008.
- Frontend assets are not registered outside the order controller and cannot activate the checkout while readiness is closed.

## Testing and release gate

Smoke coverage verifies the version-isolation contract, provider method shape, Core session reuse, step rendering lifecycle, media-hook gate and upgrade script. PHP syntax/Composer/autoload/JavaScript gates run in CI.

This is not a substitute for a live PrestaShop runtime test. Before `INTEGRATION_SHELL_READY` may become true, the repository still needs deterministic integration coverage proving at least: 9.0/9.1 process replacement, 9.2+ provider resolution/fallback, disabled-module native checkout, native provider conflict behavior, session persistence, real Smarty rendering and browser asset/bootstrap lifecycle.

The next implementation priority is therefore a repeatable PrestaShop runtime integration harness for these two version paths, followed by the still-missing identity/customer flow and address/carrier mutation endpoints.

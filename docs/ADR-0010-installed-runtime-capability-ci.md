# ADR-0010: Installed PrestaShop runtime capability CI

## Status

Accepted.

## Context

Source-level smoke tests proved the intended checkout integration contracts but could not prove that PrestaShop actually autoloads the expected Core classes, exposes the expected hooks, installs the module successfully, or reports native `ps_onepagecheckout` state in a clean shop.

The first installed-runtime run exposed exactly that gap: `PrestaShopRuntimeProbe::hookExists()` checked `class_exists('Hook', false)`. In a fresh CLI process the legacy `Hook` class had not necessarily been loaded yet, so a valid Core capability was misclassified as unavailable. Both PrestaShop 9.1 and 9.2 therefore resolved to the fail-closed `Unsupported` strategy even though their real hooks existed.

## Decision

A dedicated GitHub Actions workflow provisions real PrestaShop installations backed by MariaDB and installs `jzonepagecheckout` through PrestaShop's module CLI before running capability assertions.

The initial compatibility matrix is:

- PrestaShop 9.1.5: validates the legacy `actionCheckoutRender` strategy and confirms the 9.2 provider interface is absent;
- PrestaShop 9.2.0-beta.1: validates the `actionCheckoutBuildProcess` provider strategy and installs a pinned native `ps_onepagecheckout` revision to prove conflict detection.

Both jobs also verify module installation/enabled state, version-specific hook registration, the `actionFrontControllerSetMedia` hook, and that enabling `JZOPC_CHECKOUT_ENABLED` still cannot activate custom checkout while `INTEGRATION_SHELL_READY=false`.

`PrestaShopRuntimeProbe` now uses normal autoload-capable `class_exists()` checks for legacy Core classes. Capability detection must answer whether the runtime can provide a class, not whether another code path happened to touch that class earlier in the current process.

The workflow builds Hummingbird assets before running the source-tree PrestaShop installer because the real installer requires the theme distribution assets that are not committed in the Core source checkout.

## Consequences

- The module now has deterministic installed-runtime coverage in addition to source smoke tests.
- A clean-process autoload regression that would silently force native fallback is caught by CI.
- The test matrix proves capability/hook/install/conflict behavior, but it does not yet open the checkout readiness gate.
- This milestone does not prove live Smarty shell rendering, browser navigation, mutation HTTP routing, provider/reference-hook takeover, representative carrier/payment behavior, or final order placement.
- No module version bump is required because this milestone adds test infrastructure and corrects runtime capability probing without changing configuration schema, database schema, or install/upgrade data.

## Next milestone

Extend the runtime harness from installed capability checks to a controlled live checkout request/browser harness. That harness must prove shell/asset rendering, native fallback when disabled or conflicted, version-specific process takeover when explicitly test-enabled, and payment/carrier lifecycle compatibility before `INTEGRATION_SHELL_READY` can be reconsidered for production code.

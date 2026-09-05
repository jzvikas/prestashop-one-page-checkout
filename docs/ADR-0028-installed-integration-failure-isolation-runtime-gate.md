# ADR-0028: Installed integration failure-isolation runtime gate

## Status

Accepted for test infrastructure. The new installed-runtime contract is committed but has not executed because GitHub has not created Actions checks for the current branch. `INTEGRATION_SHELL_READY=false` remains unchanged.

## Context

ADR-0027 moved risky checkout shell composition before version-specific process takeover so a database, template, presenter or third-party hook failure can leave PrestaShop Core's native checkout path available. Source contracts prove the ordering in PHP source, but source inspection alone cannot prove that the adapter/provider classes behave as intended with real installed PrestaShop `Context`, `CheckoutSession`, translator and `CheckoutProcess` objects.

A production test switch that bypasses `INTEGRATION_SHELL_READY` would weaken the activation boundary and is not acceptable. Likewise, injecting a debug endpoint or configuration flag just for CI would create a production-accessible surface that the checkout does not otherwise need.

## Decision

Add `tests/Runtime/IntegrationFailureIsolationContract.php` to every installed PrestaShop 9.0/9.1/9.2 runtime matrix job.

The contract uses the real installed Core runtime and introduces failure only through test-local interface implementations under `tests/Runtime`.

### PrestaShop 9.0 / 9.1

The contract:

1. creates a real persisted runtime cart;
2. initializes a real `OrderController` front container and obtains its Core `CheckoutSession`;
3. builds a real Core `CheckoutProcess` around that session;
4. constructs `CheckoutShellRenderer` with a test-only `CheckoutServerSelectionsStoreInterface` whose `load()` throws a controlled persistence-read exception;
5. invokes `LegacyCheckoutRenderAdapter::replaceProcess()` with that failing builder;
6. requires the injected exception to occur before replacement assignment;
7. requires the reference-bearing `checkoutProcess` payload to still contain the exact original Core process object;
8. requires that original Core process to still own the exact original Core `CheckoutSession`.

This turns ADR-0027's source-ordering rule into an installed-object identity contract: eager shell failure cannot partially replace the legacy Core process.

### PrestaShop 9.2+

The production hook must prepare the shell before exposing a provider. The private production readiness constant is deliberately not bypassed in the runtime test, so the contract does not pretend to execute an active production provider hook while readiness is closed.

Instead it verifies the two installed runtime properties that make the source-level hook fallback safe:

1. the same controlled persistence-read failure is observable through `CheckoutProcessBuilder::prepareShell()` before a provider can be constructed from that shell;
2. once `CheckoutProcessProvider` is given already-prepared HTML, Core's later `buildCheckoutProcess()` call builds a real Core process with the exact supplied `CheckoutSession` without touching the failing selection store or any other shell-render dependency again.

The source containment contract continues to verify that the actual module hook catches `prepareShell()` failure and returns `null`. A later controlled HTTP/browser failure-injection harness must still prove Core's provider resolver/order controller chooses native checkout on the real request path.

### No production bypass

The runtime fixture:

- does not write `JZOPC_CHECKOUT_ENABLED`;
- does not modify or reflectively override `INTEGRATION_SHELL_READY`;
- does not add a debug/test endpoint;
- does not call `PaymentModule::validateOrder()` or create an order;
- does not send finalization release/begin actions;
- does not mutate production service definitions.

Failure injection exists only in the runtime test process.

## Installed shell contract strengthening

The installed Smarty shell runtime contract is also extended to require the current finalization bootstrap, not only the older identity/address/carrier/payment/agreement surface:

- non-empty server-generated `data-jzopc-finalization-url` targeting the module `finalize` controller;
- exactly one final-submit control and final-status surface;
- a fresh runtime cart renders exactly one server-derived `data-jzopc-finalization-reserved="0"` marker.

This prevents the installed runtime matrix from becoming green while the final-submit bootstrap is missing or reservation-state rendering is malformed.

## Consequences

- legacy eager-failure isolation is now represented by a real installed Core process/session identity test;
- the 9.2 provider is runtime-locked to an already-prepared-shell model and cannot silently regress to late DB/template shell composition;
- the production readiness gate and activation policy are not weakened for testing;
- full request-path native fallback is still a browser/HTTP release blocker, especially for injected asset, renderer/template and provider-preparation failures;
- no schema, configuration, hook or module-version migration is introduced.

## Verification state

The runtime contract, workflow wiring, source smoke contract and strengthened installed Smarty assertions are committed. GitHub currently reports no checks/statuses for the branch, so none of these new installed-runtime assertions are claimed as passed.

Before readiness can change, the configured PrestaShop 9.0.3, 9.1.5 and 9.2 runtime jobs must execute successfully, followed by controlled HTTP/browser failure injection proving native checkout remains usable after shell DB/template/service and asset-registration failures on the real request path.

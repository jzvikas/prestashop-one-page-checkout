# ADR-0026: Installed integration failure-isolation runtime gate

## Status

Accepted for test infrastructure. `INTEGRATION_SHELL_READY=false` remains unchanged. Previous runtime #61 proved the normal installed 9.0/9.1/9.2 graph; this ADR adds controlled failure-isolation evidence to that matrix.

## Context

ADR-0025 moves risky checkout shell composition before process takeover. Source ordering alone does not prove that real installed PrestaShop `Context`, `CheckoutSession`, translator and `CheckoutProcess` objects preserve Core fallback semantics when shell preparation fails.

Opening the private readiness constant or adding a production debug endpoint only for CI would weaken the safety boundary. Failure injection therefore has to remain test-local.

## Decision

Add `tests/Runtime/IntegrationFailureIsolationContract.php` to every installed PrestaShop 9.0/9.1/9.2 runtime job.

The contract uses a real installed PrestaShop runtime and a test-only `CheckoutServerSelectionsStoreInterface` implementation whose `load()` throws a controlled persistence-read exception.

### PrestaShop 9.0 / 9.1

The contract creates a real cart and `OrderController`, obtains the Core `CheckoutSession`, wraps it in the original Core `CheckoutProcess`, then invokes `LegacyCheckoutRenderAdapter` with the failing builder. It requires:

- the injected shell-read exception to occur before replacement assignment;
- the reference-bearing hook payload to still contain the exact original Core process;
- that Core process to still own the exact original Core session.

### PrestaShop 9.2+

The contract requires the same injected failure to be observable through `CheckoutProcessBuilder::prepareShell()` before provider exposure. It then constructs a provider with deterministic already-prepared HTML and requires `buildCheckoutProcess()` to reuse the exact Core session without re-entering the failing shell/persistence dependency.

The production hook source contract separately requires a failed `prepareShell()` to return `null`, which is the Core native-fallback path.

### No production bypass

The runtime test:

- does not write `JZOPC_CHECKOUT_ENABLED`;
- does not modify or reflectively override `INTEGRATION_SHELL_READY`;
- adds no production debug/test route;
- sends no finalization begin/release action;
- does not call `PaymentModule::validateOrder()` or create an order;
- does not mutate production service definitions.

## Consequences

A green installed matrix proves the prepared-shell and original-process identity contract against real supported PrestaShop runtime objects. It still does not prove full browser request fallback, payment-module behavior or production readiness.

## Verification

`.github/workflows/prestashop-runtime.yml` executes the contract for 9.0.3, 9.1.5 and 9.2.0-beta.1. `CheckoutInstalledIntegrationFailureIsolationContractSmokeTest.php` locks the workflow and non-bypass properties.

Controlled HTTP/Chromium takeover/fallback remains the next gate after this installed contract is green.

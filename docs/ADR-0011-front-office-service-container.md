# ADR-0011: Front-office module service container entry point

## Status

Accepted.

## Context

The installed-runtime Core process contract initially failed on PrestaShop 9.1.5 even though the module installed successfully and `config/services.yml` defined `CheckoutProcessBuilder`, `LegacyCheckoutRenderAdapter` and the frontend asset registrar as public services.

A real `OrderController` uses PrestaShop's legacy front-office container. In PrestaShop 9.1 Core, `FrontController::buildContainer()` resolves `ContainerBuilder::getContainer('front', ...)`, and that builder registers `LoadServicesFromModulesPass('front')`. The compiler pass therefore loads module services from `config/front/services.yml`, not the generic `config/services.yml` file used by the Symfony application kernel.

Consequently, `Module::get(CheckoutProcessBuilder::class)` could not resolve the module service graph in a real front-office controller even though the same services were valid in the generic Symfony container. This was a production integration boundary bug, not a test-fixture issue.

## Decision

1. Keep one canonical service graph in `config/services.yml`.
2. Add `config/front/services.yml` as the PrestaShop legacy front-office entry point and import `../services.yml` from it.
3. Do not duplicate the service graph between generic and front scopes; duplicated definitions would drift and make version-specific failures harder to diagnose.
4. Continue exposing only the narrow services that legacy module entry points resolve directly through `Module::get()`; internal dependencies remain private and constructor-injected.
5. Keep `INTEGRATION_SHELL_READY=false`. Making the real front container able to resolve checkout services is necessary plumbing, not evidence that live checkout takeover is safe.
6. Guard the front service entry point with a source smoke assertion and the real installed PrestaShop runtime contract. The runtime contract must resolve `CheckoutProcessBuilder` from a real `OrderController` front container and build the Core checkout process on both tested runtime families.

## Consequences

- PrestaShop 9.1/9.2 front-office controllers can resolve the module's shared checkout service graph without a service-locator workaround or Core override.
- The same constructor-injected service definitions remain authoritative for Symfony and legacy front contexts.
- Front-office service-scope regressions are caught before the readiness gate can be reconsidered.
- No configuration schema, database schema, hook registration or install data changes are introduced, so no module version bump is required.
- Live Smarty/HTTP rendering, hook/provider resolution with takeover enabled, browser mutation routing and payment/carrier E2E remain separate gates.

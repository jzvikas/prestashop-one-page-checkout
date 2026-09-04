# Architecture

This document describes architecture that exists in the repository today. It is intentionally updated as implementation milestones land.

## Integration boundary

`jzonepagecheckout.php` is a thin PrestaShop module bootstrap. Version and conflict decisions live under `src/Integration`.

The integration layer currently contains:

- `CheckoutCapabilityDetector`: discovers the checkout extension mechanism actually available at runtime;
- `CheckoutRuntimeCapabilities`: immutable capability snapshot;
- `CheckoutHookPlan`: selects the single checkout hook that belongs to the supported PrestaShop version family;
- `CheckoutActivationPolicy`: fail-closed decision for whether custom checkout takeover is allowed;
- `PrestaShopRuntimeProbe`: isolates legacy/static PrestaShop capability lookups behind `RuntimeProbeInterface`.

No PrestaShop Core file or override is used.

## Version strategy

### PrestaShop 9.0 / 9.1

Install registers only `actionCheckoutRender`. Core 9.1.5 builds its native `CheckoutProcess`, then passes it by reference to this hook. Until the dedicated adapter exists, our hook deliberately performs no mutation, so the native process remains authoritative.

### PrestaShop 9.2+

Install registers only `actionCheckoutBuildProcess`. Core aggregates hook output and accepts a custom `CheckoutProcessProviderInterface` only when exactly one enabled valid provider exists. The current hook returns `null`, which Core ignores, therefore native checkout remains the fallback.

Before a future provider is allowed to activate, `CheckoutActivationPolicy` also blocks takeover while the native `ps_onepagecheckout` module is enabled. This avoids intentionally creating a multiple-provider fallback conflict.

### Unsupported runtimes

Versions before 9.0 and future major versions from 10.0 are rejected by the current capability/hook plan. A new major version must be explicitly investigated before support is widened.

## Activation model

Module enabled state and checkout takeover are separate concepts.

`JZOPC_CHECKOUT_ENABLED` is the merchant-facing checkout-flow feature flag. It is created disabled during installation and is forced disabled when the module is disabled. In addition, the code has an internal integration-readiness gate that is currently false.

Custom checkout activation is allowed only when all are true:

1. the runtime exposes a supported strategy;
2. no enabled native 9.2 OPC provider conflicts with this module;
3. the checkout feature flag is enabled;
4. the version-specific integration shell is marked ready.

This means a partial deployment cannot silently replace checkout. Failure always falls back to the native PrestaShop flow at this stage.

## Install / disable / uninstall lifecycle

Installation:

1. verifies Composer-loaded integration classes are available;
2. derives one version-specific checkout hook;
3. calls the parent module install;
4. creates the checkout feature flag disabled;
5. registers the selected hook;
6. rolls back configuration and parent install if hook registration fails.

Disable forces the checkout feature flag off before disabling the module. Uninstall removes module-owned configuration and delegates hook cleanup to PrestaShop's module lifecycle.

No custom database table exists in this phase.

## Server-authoritative checkout state

The application layer under `src/Checkout` provides the transport-independent state contract used by future AJAX controllers:

- `CheckoutState` validates and normalizes the server snapshot;
- `CheckoutStateVersioner` creates an opaque canonical state token;
- `StaleCheckoutStateGuard` rejects missing/outdated versions using constant-time comparison;
- `CheckoutSectionDependencyResolver` maps mutations to every downstream section that must be rebuilt;
- `CheckoutRefreshResult` and `CheckoutError` define the stable machine-readable response contract.

This layer deliberately contains no prices supplied by a browser. Monetary truth remains in PrestaShop Core; server adapters fingerprint recalculated cart/totals data rather than trust submitted values. See `ADR-0002-server-authoritative-checkout-state.md`.

## PrestaShop state adapter

`PrestaShopCheckoutStateFactory` is the infrastructure bridge from a loaded PrestaShop `Context`/`Cart` into the application `CheckoutState` contract. It deliberately reads identity from the server-side cart, reuses Core `CartChecksum`/`AddressChecksum`, augments checkout-specific cart state that Core's checksum does not cover, and fingerprints only server-recalculated `Cart::getOrderTotal()` values.

`CheckoutServerSelections` carries already server-validated payment/agreement selections into the snapshot. It is not a browser request DTO and contains no monetary values.

The Symfony service configuration explicitly registers stateless services instead of auto-registering every class below `src/`, so enums/value objects with scalar constructors are not treated as services.

See `ADR-0003-prestashop-checkout-state-adapter.md`.

## Next application boundary

The next milestone is the shared AJAX transport/security shell: cart/session binding, CSRF validation, stale-state enforcement, structured JSON responses and a read-only refresh endpoint. Mutation-specific customer/address behavior should be added only after those cross-cutting guards are reusable and tested.

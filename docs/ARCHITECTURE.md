# Architecture

This document describes architecture that exists in the repository today. It intentionally does not describe unfinished work as completed.

## Integration boundary and version strategy

`jzonepagecheckout.php` remains a thin PrestaShop bootstrap. Runtime/version decisions and checkout-process adapters live under `src/Integration`.

- PrestaShop 9.0/9.1 registers `actionCheckoutRender`. `LegacyCheckoutRenderAdapter` validates the Core process supplied by the reference-bearing hook, reuses its current `CheckoutSession`, and replaces only the process object with the module-built process.
- PrestaShop 9.2+ registers `actionCheckoutBuildProcess`. `Integration/Provider/CheckoutProcessProvider` implements the exact `CheckoutProcessProviderInterface` contract and delegates to the same module process builder.
- The 9.2-only provider class is not loaded on older 9.x runtimes: module code checks `interface_exists()` before it resolves the provider class.
- `CheckoutActivationPolicy` blocks custom takeover when native `ps_onepagecheckout` is enabled and fails closed on unsupported capability combinations.
- `INTEGRATION_SHELL_READY` remains `false`, so neither adapter can currently take over live checkout. Public mutation controllers therefore still return `checkout_unavailable`.

`CheckoutProcessBuilder` creates a real Core `CheckoutProcess` around a single `CheckoutShellStep`. The step extends `AbstractCheckoutStep`, stays reachable/current as the one-page surface and calls Core `renderTemplate()`, preserving `actionCheckoutStepRenderTemplate`. `CheckoutShellRenderer` then renders the trusted module root from the same Core cart/session and persisted server selections used by mutation guards.

`CheckoutBrowserBootstrapFactory` builds the initial browser binding from the loaded cart, `Tools::getToken(false)`, `PrestaShopCheckoutStateFactory`, `CheckoutStateVersioner` and PrestaShop-generated module links. The shell exposes only cart ID, CSRF token, state version and payment/agreement endpoint URLs. It does not expose client-authoritative totals or a browser copy of server selections.

`actionFrontControllerSetMedia` is installed in addition to the version-specific checkout hook. It is restricted to `OrderController`, must pass the same activation policy, and delegates to `CheckoutFrontendAssetRegistrar`. Existing installations receive that hook through idempotent `upgrade/upgrade-0.3.0.php`; the version is therefore `0.3.0`.

See ADR-0001, ADR-0008 and ADR-0009.

## Server-authoritative checkout state

The application layer under `src/Checkout` owns a transport-independent state contract:

- `CheckoutState` validates and normalizes a server snapshot;
- `CheckoutStateVersioner` produces an opaque canonical state token;
- `StaleCheckoutStateGuard` rejects missing/outdated versions;
- `CheckoutSectionDependencyResolver` centralizes downstream refresh dependencies;
- `CheckoutRefreshResult` and `CheckoutError` define the stable response contract;
- `PrestaShopCheckoutStateFactory` derives identity and monetary fingerprints only from the loaded server-side Core cart.

`CheckoutServerSelections` contains only already server-validated payment/agreement selections. It is not a browser DTO.

### Selection persistence

`CheckoutServerSelectionsStoreInterface` is implemented by `DbalCheckoutServerSelectionsStore`. It persists the canonical payment option and normalized approved-agreement keys in `jzopc_checkout_selection`, keyed by `(id_shop, id_cart)` with `id_customer` as an additional ownership binding.

Shop/cart/customer identifiers always come from the loaded server-side cart. A stored customer mismatch deletes the stale row and returns empty selections. Runtime values use Doctrine DBAL parameters; the table identifier uses only the validated PrestaShop database prefix. No prices, totals, payment credentials, CSRF tokens or customer/address payloads are persisted.

The schema is created on fresh install and by `upgrade/upgrade-0.2.0.php` for earlier installations. Uninstall removes it. Successful-order and abandoned-row cleanup remains Phase 5 work.

See ADR-0002, ADR-0003 and ADR-0006.

## Mutation security and concurrency

`CheckoutMutationGuard` validates front-office CSRF, binds submitted cart ID to the cart already loaded by the current session/context, checks cart/customer identity and rejects stale state. A client cart ID is never used to load another cart.

`CheckoutCartMutex` serializes same-cart mutations with MySQL/MariaDB connection-owned advisory locks through Doctrine DBAL. Lock queries are parameterized and lock acquisition fails closed.

`CheckoutMutationOrchestrator` owns authoritative selection-state access and imposes this order:

1. cheap CSRF rejection;
2. acquire the per-cart mutex;
3. load current `CheckoutServerSelections` from the server store;
4. repeat the full mutation guard inside the critical section;
5. resolve required refreshed sections;
6. execute the operation handler with guarded state and server-loaded selections;
7. reject successful output missing required sections;
8. persist new selections only for a structurally complete successful outcome;
9. rebuild server-authoritative state/version from Core plus resulting selections;
10. release the mutex in `finally`.

Stale, CSRF-rejected, failed or incomplete mutations do not overwrite persisted selections.

`CheckoutMutationResponseMapper` maps application results to stable HTTP semantics. `JzOnePageCheckoutAbstractJsonModuleFrontController` owns no-store JSON headers plus exception containment/logging, while `JzOnePageCheckoutAbstractMutationModuleFrontController` owns POST-only transport and the fail-closed activation gate.

Concrete mutation controllers currently exist for `paymentselection` and `agreements`. Controllers collect request values but do not authorize carts, prices, modules or agreements. State-sensitive parsing and validation run inside `CheckoutMutationOrchestrator`, after mutex acquisition and fresh-state validation.

## Browser mutation transport

`views/js/checkout-mutation-client.js` is the shared browser transport for the implemented payment/agreement endpoints. It activates only inside the trusted module-owned `[data-jzopc-checkout]` root with positive cart ID, non-empty CSRF/state tokens and explicit endpoint URLs.

Every mutation sends `token`, `cartId`, prior `stateVersion` and only operation-specific identifiers. It never sends client-calculated money or a browser copy of `CheckoutServerSelections`.

A newer mutation increments a local sequence and aborts the prior request where `AbortController` is available; every response is also compared with the latest sequence. A slower old response therefore cannot overwrite newer checkout state even if cancellation races or is unavailable.

A `409 stale_state` response with `retryable=true` and a current server version may advance the local token and replay the same latest intent exactly once. Other retryable failures are not automatically replayed.

Before changing DOM, the client validates the complete returned section map. Every response key must map to a current section and returned HTML must contain exactly one matching `data-jzopc-section` root. If any replacement is invalid, none is applied. Successful application updates the root state version and emits `jzopc:section:updated` for each replaced section.

The client publishes checkout lifecycle events but does not submit payment forms or place orders.

See ADR-0007 and ADR-0008.

## Section rendering

`CheckoutSectionRendererRegistry` is fail-closed: every requested section must have exactly one renderer. Missing/duplicate renderers are programming errors, not successful partial refreshes. It passes canonical `CheckoutServerSelections` only to state-aware renderers.

### Summary

`SummarySectionRenderer` uses `PrestaShopCheckoutCartPresenter`, delegating to Core `CartPresenter` and preserving `actionPresentCart`.

### Addresses

`AddressesSectionRenderer` uses `PrestaShopCheckoutAddressBookPresenter`. It reads only the cart-bound customer, fails on cart/customer mismatch, filters addresses through `Customer::customerHasAddress()`, loads Core `Address` objects and formats them with `AddressFormat::generateAddress()`.

The current address renderer covers saved-address selection only. Add/edit address forms and public address/customer mutations remain unfinished and must preserve Core country/state/field validation.

### Delivery

`DeliverySectionRenderer` uses `PrestaShopCheckoutDeliveryOptionsPresenter`. Physical carts execute `actionCarrierProcess` before discovery and obtain the active Core checkout session through `CheckoutSessionProviderInterface`. This preserves Core delivery keys, pricing/delay presentation, `displayCarrierExtraContent`, `displayBeforeCarrier` and `displayAfterCarrier`. Virtual carts emit no shipping section.

`PrestaShopCheckoutSessionProvider` currently delegates to an active controller exposing Core `getCheckoutSession()`. Before module-owned carrier/address AJAX endpoints are exposed, a source-backed module controller/session construction path must mirror Core `OrderController`, including the improved-shipment feature-flag choice.

### Payment

`PaymentSectionRenderer` delegates discovery to Core `PaymentOptionsFinder::present()`, preserving payment discovery and `actionPresentPaymentOptions`. The template preserves option IDs/module names, binary markers, actions, inputs, additional information and module forms. Ordinary values are escaped; payment-module HTML remains an explicit trusted module boundary.

During authoritative AJAX refreshes, a radio is checked only when the fresh Core-presented module/option exactly matches the canonical persisted `module:option` selection.

`views/js/payment-controller.js` mounts re-entrantly on initial DOM and `jzopc:section:updated`, synchronizes related additional-information/payment-form containers and publishes payment lifecycle events. It deliberately never calls `submit()` or `requestSubmit()`.

`CheckoutPaymentSelectionService` re-runs the Core-backed presenter and requires exact module key, option ID and presented module-name agreement. `CheckoutPaymentSelectionMutation` performs that validation inside the orchestrator critical section and rechecks whether prior agreements remain valid.

See ADR-0004.

### Agreements

`AgreementsSectionRenderer` delegates discovery to Core `ConditionsToApproveFinder::getConditionsToApproveForTemplate()`, preserving configured shop terms plus `termsAndConditions` hook output.

The module owns accessible native checkbox markup and escapes identifiers. Core-formatted condition HTML is an explicit trusted Core/module boundary. During authoritative refresh, only canonical persisted agreement keys render checked.

`CheckoutAgreementSelectionService` regenerates the current Core set and accepts approval only when submitted keys exactly equal all currently required keys. `CheckoutAgreementSelectionMutation` executes this inside the orchestrator critical section and preserves prior persisted state on invalid/incomplete approval.

Final submission must revalidate the fresh Core condition set immediately before payment/order handoff.

See ADR-0005.

### Identity

Identity remains intentionally unregistered. Guest/account creation and login integration must preserve Core customer validation/business rules rather than ship a placeholder renderer.

## Rendering trust boundaries

Module-owned labels, identifiers and presented values are escaped by context. Raw HTML is limited to PrestaShop-defined Core/module markup: carrier extra/before/after hook HTML, payment top/additional-information/forms, Core-formatted checkout legal conditions, and section HTML already rendered by those trusted server renderers into the module shell.

Those raw boundaries must never be widened to browser-controlled or arbitrary customer-stored HTML.

## Refresh graph

The dependency resolver is conservative. Address/cart changes refresh addresses, delivery, payment, agreements and summary; payment selection refreshes payment, agreements and summary; agreement changes refresh agreements. Correctness is preferred over micro-optimizing renders.

## Testing state

The smoke suite covers capability/activation logic, state/versioning, CSRF/cart binding, mutex/orchestrator behavior, selection-store/schema behavior, upgrade contracts, response mapping, address selection/rendering, Core-backed address/delivery/payment/agreement presenters, payment JavaScript behavior, payment selection validation, agreement exact-set validation, authoritative selection restoration, guarded endpoint contracts and stale-safe browser mutation transport.

Version-specific integration contract coverage now also verifies:

- the 9.2 provider implements the exact provider methods and remains isolated from the generic 9.x bootstrap path;
- 9.0/9.1 process replacement reuses Core's current `CheckoutSession`;
- the module process contains the real shell step;
- shell-step rendering goes through Core `renderTemplate()`;
- the readiness gate remains closed;
- frontend media registration stays OrderController-only and activation-gated;
- `0.3.0` has an idempotent media-hook upgrade path.

GitHub Actions validates Composer metadata and production autoload installation, PHP 8.4 syntax, JavaScript syntax with Node.js 22 and the full smoke suite.

CI does **not** yet boot real PrestaShop 9.0/9.1/9.2 installations, execute schema/media-hook upgrades against MySQL/MariaDB, exercise real provider resolution or reference-hook replacement, render Smarty in a real theme, or run browser E2E with representative carrier/payment modules. Those are required before production readiness.

## Next application boundary

The code-level version-specific checkout process adapters now exist, but activation remains deliberately fail-closed. The next highest-priority milestone is a deterministic PrestaShop runtime integration harness proving:

1. 9.0/9.1 `actionCheckoutRender` replacement with the original Core checkout session;
2. 9.2+ provider resolution and Core fallback when zero/multiple providers are active;
3. native `ps_onepagecheckout` conflict-safe disable/fallback behavior;
4. real Smarty shell/bootstrap rendering and frontend asset registration;
5. disabled module/configuration always preserves native checkout;
6. browser mutation lifecycle works without breaking payment/carrier hooks.

Only after those gates are green may `INTEGRATION_SHELL_READY` be reconsidered. Identity/customer flow, address/carrier mutations, Phase 5 final validation, duplicate-order/idempotency protection, selection cleanup and native payment-module handoff remain release blockers after the integration harness.

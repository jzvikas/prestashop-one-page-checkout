# Architecture

This document describes architecture that exists in the repository today. It intentionally does not describe unfinished work as completed.

## Integration boundary and version strategy

`jzonepagecheckout.php` remains a thin PrestaShop bootstrap. Runtime/version decisions live under `src/Integration`.

- PrestaShop 9.0/9.1 registers `actionCheckoutRender`; the dedicated legacy adapter is not active yet, so Core checkout remains authoritative.
- PrestaShop 9.2+ registers `actionCheckoutBuildProcess`; the module currently returns no custom provider, so Core/native checkout remains authoritative.
- `CheckoutActivationPolicy` blocks custom takeover when native `ps_onepagecheckout` is enabled.
- unsupported capability combinations fail closed instead of referencing unavailable classes.

`JZOPC_CHECKOUT_ENABLED` is separate from module enabled state, defaults off and is forced off when the module is disabled. The internal integration-readiness gate also remains false. Public mutation controllers call the same active-checkout decision and return `checkout_unavailable` while the custom integration is incomplete. There are no Core overrides.

The next integration milestone must make the PrestaShop 9.0/9.1 and 9.2+ paths produce a real module-owned one-page shell and trusted browser bootstrap without opening the activation gate prematurely. The shell will be responsible for asset registration and for emitting the current cart ID, CSRF token, state version and mutation endpoint URLs only after the integration policy has allowed the custom checkout.

See `ADR-0001-checkout-integration-strategy.md`.

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

See `ADR-0002-server-authoritative-checkout-state.md`, `ADR-0003-prestashop-checkout-state-adapter.md` and `ADR-0006-server-side-checkout-selection-persistence.md`.

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

Concrete mutation controllers currently exist for:

- `paymentselection`, delegating to `CheckoutPaymentSelectionMutation`;
- `agreements`, delegating to `CheckoutAgreementSelectionMutation`.

Controllers collect request values but do not authorize carts, prices, modules or agreements. State-sensitive parsing and validation run inside `CheckoutMutationOrchestrator`, after mutex acquisition and fresh-state validation.

## Browser mutation transport

`views/js/checkout-mutation-client.js` is the shared browser transport foundation for the implemented payment/agreement endpoints. It is deliberately dormant unless a module-owned `[data-jzopc-checkout]` root contains a complete trusted bootstrap:

- positive `data-jzopc-cart-id`;
- non-empty `data-jzopc-csrf-token`;
- non-empty `data-jzopc-state-version`;
- explicit payment and agreement endpoint URLs.

Every mutation sends `token`, `cartId`, prior `stateVersion` and only operation-specific identifiers. It never sends client-calculated money or a browser copy of `CheckoutServerSelections`.

Race protection is layered. A newer mutation increments a local sequence and aborts the prior request where `AbortController` is available; every response is also compared with the latest sequence. A slower old response therefore cannot overwrite newer checkout state even if cancellation is unavailable or races with completion.

A `409 stale_state` response with `retryable=true` and a server current version may advance the local token and replay the same latest user intent exactly once. Other retryable failures are not automatically replayed.

Before changing DOM, the client validates the complete returned section map. Every response key must be a safe section identifier, the current DOM must contain that section, and returned HTML must contain exactly one matching `data-jzopc-section` root. If any replacement is invalid, none of the response sections is applied. After successful prevalidation the client advances the root state version, replaces the response section set and emits `jzopc:section:updated` for each replacement. Structured validation failures can therefore refresh authoritative server HTML while still emitting `jzopc:checkout:validation-failed`.

The client publishes `jzopc:checkout:initialized`, `jzopc:checkout:updating`, `jzopc:checkout:updated`, `jzopc:checkout:validation-failed` and `jzopc:checkout:error`. It does not submit payment forms or place orders.

See `ADR-0007-stale-safe-browser-mutation-transport.md`.

## Section rendering

`CheckoutSectionRendererRegistry` is fail-closed: every requested section must have exactly one renderer. Missing/duplicate renderers are programming errors, not successful partial refreshes. It can pass canonical `CheckoutServerSelections` only to state-aware renderers.

### Summary

`SummarySectionRenderer` uses `PrestaShopCheckoutCartPresenter`, delegating to Core `CartPresenter` and preserving `actionPresentCart`.

### Addresses

`AddressesSectionRenderer` uses `PrestaShopCheckoutAddressBookPresenter`. It reads only the cart-bound customer, fails on cart/customer mismatch, filters addresses through `Customer::customerHasAddress()`, loads Core `Address` objects and formats them with `AddressFormat::generateAddress()`.

The current address renderer covers saved-address selection only. Add/edit address forms and public address/customer mutations remain unfinished and must preserve Core country/state/field validation.

### Delivery

`DeliverySectionRenderer` uses `PrestaShopCheckoutDeliveryOptionsPresenter`. Physical carts execute `actionCarrierProcess` before discovery and obtain the active Core checkout session through `CheckoutSessionProviderInterface`. This preserves Core delivery keys, pricing/delay presentation, `displayCarrierExtraContent`, `displayBeforeCarrier` and `displayAfterCarrier`. Virtual carts emit no shipping section.

`PrestaShopCheckoutSessionProvider` currently delegates to an active controller exposing Core `getCheckoutSession()`. Before module-owned carrier/address AJAX endpoints are exposed, a source-backed module controller/session construction path must mirror Core `OrderController`, including the improved-shipment feature-flag choice.

### Payment

`PaymentSectionRenderer` uses `PrestaShopCheckoutPaymentOptionsPresenter`, which delegates discovery to Core `PaymentOptionsFinder::present()`, preserving payment discovery and `actionPresentPaymentOptions`.

The payment template preserves option IDs/module names, binary markers, actions, inputs, additional information and module forms. Ordinary values are escaped. `displayPaymentTop`, `PaymentOption::additionalInformation` and module forms are explicit trusted payment-module HTML boundaries.

During authoritative AJAX refreshes, a radio is checked only when the fresh Core-presented module/option exactly matches the canonical persisted `module:option` selection.

`views/js/payment-controller.js` mounts re-entrantly on initial DOM and `jzopc:section:updated`, synchronizes related additional-information/payment-form containers and publishes payment lifecycle events. It deliberately never calls `submit()` or `requestSubmit()`.

`CheckoutPaymentSelectionParser` accepts a bounded option ID/module contract. `CheckoutPaymentSelectionService` re-runs the Core-backed presenter and requires exact module key, option ID and presented module-name agreement. `CheckoutPaymentSelectionMutation` performs that validation inside the orchestrator critical section, rechecks whether any prior agreements are still exactly valid, renders all dependency-resolved sections and lets the orchestrator persist only a complete success.

See `ADR-0004-server-validated-payment-selection.md`.

### Agreements

`AgreementsSectionRenderer` delegates discovery to Core `ConditionsToApproveFinder::getConditionsToApproveForTemplate()`, preserving configured shop terms plus `termsAndConditions` hook output.

The module owns accessible native checkbox markup and escapes identifiers. Core-formatted condition HTML is an explicit trusted Core/module boundary. During authoritative refresh, only canonical persisted agreement keys render checked.

`CheckoutAgreementSelectionParser` accepts a bounded list of safe identifiers. `CheckoutAgreementSelectionService` regenerates the current Core set and accepts approval only when submitted keys exactly equal all currently required keys. `CheckoutAgreementSelectionMutation` executes this inside the orchestrator critical section and preserves prior persisted state on invalid/incomplete approval.

Final submission must revalidate the fresh Core condition set immediately before payment/order handoff.

See `ADR-0005-server-validated-checkout-agreements.md`.

### Identity

Identity remains intentionally unregistered. Guest/account creation and login integration must preserve Core customer validation/business rules rather than ship a placeholder renderer.

## Rendering trust boundaries

Module-owned labels, identifiers and presented values are escaped by context. Raw HTML is limited to PrestaShop-defined Core/module markup:

- carrier extra/before/after hook HTML;
- payment top/additional-information/forms;
- Core-formatted checkout legal conditions.

Those raw boundaries must never be widened to browser-controlled or arbitrary customer-stored HTML.

## Refresh graph

The dependency resolver is conservative. Address/cart changes refresh addresses, delivery, payment, agreements and summary; payment selection refreshes payment, agreements and summary; agreement changes refresh agreements. Correctness is preferred over micro-optimizing renders.

## Testing state

The smoke suite covers capability/activation logic, state/versioning, CSRF/cart binding, mutex/orchestrator behavior, selection-store/schema behavior, upgrade contract, response mapping, address selection/rendering, Core-backed address/delivery/payment/agreement presenters, payment JavaScript behavior, payment selection validation and agreement exact-set validation.

It also verifies authoritative payment/agreement selection restoration, guarded endpoint source contracts, POST/405 and inactive-checkout behavior, and the browser mutation-client contract for bootstrap bindings, request payloads, AbortController/sequence race guards, bounded stale recovery, response prevalidation and section lifecycle events.

GitHub Actions validates Composer metadata and production autoload installation, PHP 8.4 syntax, JavaScript syntax with Node.js 22 and the full smoke suite.

CI does **not** yet boot a real PrestaShop installation, execute the `0.1.0 -> 0.2.0` upgrade against MySQL/MariaDB, exercise live module front-controller routing, render Smarty in a real theme or run browser E2E with representative carrier/payment modules. Those remain required before production readiness.

## Next application boundary

The browser mutation transport milestone is implemented but intentionally dormant. The next highest-priority milestone is the real version-specific checkout shell/bootstrap integration:

1. PrestaShop 9.0/9.1 legacy `actionCheckoutRender` adapter;
2. PrestaShop 9.2+ `CheckoutProcessProviderInterface` integration through `actionCheckoutBuildProcess`;
3. native `ps_onepagecheckout` conflict-safe activation;
4. module-owned one-page root rendering, asset registration and trusted cart/CSRF/state/endpoint bootstrap;
5. runtime tests proving disable/fallback behavior and that the stale-safe mutation client works without breaking payment/carrier lifecycle expectations.

The activation gate must remain closed until that integration is proven. After the integration boundary, Phase 5 must add full final checkout validation, duplicate-order/idempotency protection, selection-row lifecycle cleanup and native payment-module handoff. Carrier/address endpoints remain blocked on the module-owned Core checkout-session construction boundary described above.

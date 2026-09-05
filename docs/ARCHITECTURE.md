# Architecture

This document describes architecture that exists in the repository today. It is updated as implementation milestones land and intentionally does not describe unfinished work as completed.

## Integration boundary and version strategy

`jzonepagecheckout.php` is a thin PrestaShop bootstrap. Runtime/version decisions live under `src/Integration`.

- PrestaShop 9.0/9.1 registers only `actionCheckoutRender`; the dedicated legacy adapter is not active yet, so Core checkout remains authoritative.
- PrestaShop 9.2+ registers only `actionCheckoutBuildProcess`; the module currently returns no custom provider, so Core/native checkout remains authoritative.
- `CheckoutActivationPolicy` blocks custom takeover when the native `ps_onepagecheckout` provider is enabled.
- unsupported capability combinations fail closed rather than referencing unavailable classes.

`JZOPC_CHECKOUT_ENABLED` is separate from module enabled state, starts disabled and is forced off when the module is disabled. The internal integration-readiness gate also remains false. Public checkout mutation controllers additionally call `isCustomCheckoutActive()` and return a stable `checkout_unavailable` response while that gate is closed, so the existence of those routes cannot expose a partial custom checkout. There are no Core overrides.

The module owns one small checkout-selection table introduced in version `0.2.0`; install/upgrade/uninstall lifecycle manages that schema.

See `ADR-0001-checkout-integration-strategy.md` and `ADR-0006-server-side-checkout-selection-persistence.md`.

## Server-authoritative checkout state

The application layer under `src/Checkout` owns a transport-independent state contract:

- `CheckoutState` validates/normalizes a server snapshot;
- `CheckoutStateVersioner` produces an opaque canonical state token;
- `StaleCheckoutStateGuard` rejects missing/outdated versions;
- `CheckoutSectionDependencyResolver` centralizes downstream refresh dependencies;
- `CheckoutRefreshResult` and `CheckoutError` define the stable result contract;
- `PrestaShopCheckoutStateFactory` derives identity and monetary fingerprints only from the loaded server-side Core cart.

`CheckoutServerSelections` contains only already server-validated payment/agreement selections. It is not a browser DTO.

### Selection persistence

`CheckoutServerSelectionsStoreInterface` is implemented by `DbalCheckoutServerSelectionsStore`. It persists the canonical payment option and normalized approved-agreement keys in `jzopc_checkout_selection`, keyed by `(id_shop, id_cart)` with `id_customer` stored as an additional ownership binding.

Shop/cart/customer identifiers always come from the already loaded server-side cart. A row whose stored customer does not match the current cart customer is deleted and treated as empty state. Runtime values use Doctrine DBAL parameters; the table identifier uses only the validated PrestaShop database prefix. No prices, totals, payment credentials, CSRF tokens or customer/address payloads are persisted.

The schema is created on fresh install and by `upgrade/upgrade-0.2.0.php` for earlier installations. Uninstall removes it. Successful-order and abandoned-row lifecycle cleanup remains part of the final checkout lifecycle milestone.

See `ADR-0002-server-authoritative-checkout-state.md`, `ADR-0003-prestashop-checkout-state-adapter.md` and `ADR-0006-server-side-checkout-selection-persistence.md`.

## Mutation security and concurrency

`CheckoutMutationGuard` validates front-office CSRF, binds the submitted cart ID to the cart already loaded by the current session/context, checks cart/customer identity and rejects stale state. A client cart ID is never used to load another cart.

`CheckoutCartMutex` serializes same-cart mutations with MySQL/MariaDB connection-owned advisory locks through the Doctrine DBAL connection. Lock queries are parameterized and lock acquisition fails closed.

`CheckoutMutationOrchestrator` owns authoritative selection-state access and imposes this order:

1. cheap CSRF rejection;
2. acquire the per-cart mutex;
3. load current `CheckoutServerSelections` from the server store;
4. repeat the full mutation guard in the critical section using those selections;
5. resolve required refreshed sections;
6. run the operation handler, passing the guarded state plus server-loaded selections;
7. reject successful output missing required sections;
8. persist new selections only for a structurally complete successful outcome;
9. rebuild server-authoritative state/version from Core plus the resulting selections;
10. release the mutex in `finally`.

Stale, CSRF-rejected, failed or incomplete mutations do not overwrite persisted selections. This prevents controllers from injecting their own notion of current payment/agreement state and makes persistence part of the same serialized operation boundary as stale-state validation.

`CheckoutMutationResponseMapper` maps application results to stable HTTP semantics. `JzOnePageCheckoutAbstractJsonModuleFrontController` owns no-store JSON headers plus exception containment/logging, while `JzOnePageCheckoutAbstractMutationModuleFrontController` owns the final POST-only transport gate and the fail-closed custom-checkout activation gate.

Two concrete module front controllers now exist:

- `paymentselection` delegates only to the public `CheckoutPaymentSelectionMutation` application service plus the response mapper;
- `agreements` delegates only to the public `CheckoutAgreementSelectionMutation` application service plus the response mapper.

They read request values but do not authorize carts, prices, payment modules or agreements themselves. All state-sensitive parsing/validation occurs in their application mutation closures inside `CheckoutMutationOrchestrator`, after the cart mutex and fresh-state guard. While `INTEGRATION_SHELL_READY` is false, both controllers fail closed before any mutation service executes.

## Section rendering

`CheckoutSectionRendererRegistry` is fail-closed: every requested section must have exactly one renderer. Missing/duplicate renderers are programming errors, not successful partial refreshes. It can additionally pass canonical `CheckoutServerSelections` to renderers implementing `CheckoutStateAwareSectionRendererInterface`; browser copies are never used to restore checked UI state.

### Summary

`SummarySectionRenderer` uses `PrestaShopCheckoutCartPresenter`, which delegates to Core `CartPresenter`, preserving `actionPresentCart`. Module-owned Smarty markup is namespaced and escaped.

### Addresses

`AddressesSectionRenderer` uses `PrestaShopCheckoutAddressBookPresenter`. It reads only the cart-bound context customer, fails on cart/customer mismatch, filters saved addresses through `Customer::customerHasAddress()`, loads Core `Address` objects and formats them with `AddressFormat::generateAddress()`.

The current address renderer covers saved-address selection only. Add/edit address forms remain a later Phase 2 slice and must reuse Core country/state/field validation.

### Delivery

`DeliverySectionRenderer` uses `PrestaShopCheckoutDeliveryOptionsPresenter`. Physical carts execute `actionCarrierProcess` before option discovery and obtain the active Core checkout session through `CheckoutSessionProviderInterface`. This preserves Core delivery-option keys, carrier pricing/delay presentation, `displayCarrierExtraContent`, `displayBeforeCarrier` and `displayAfterCarrier`. Virtual carts emit no shipping section.

`PrestaShopCheckoutSessionProvider` currently obtains `getCheckoutSession()` from the active controller and fails closed otherwise. Before module-owned carrier/address AJAX endpoints are exposed, a source-backed module controller/session construction path must mirror Core `OrderController`, including the improved-shipment feature-flag choice.

### Payment

`PaymentSectionRenderer` uses `PrestaShopCheckoutPaymentOptionsPresenter`, which requires a loaded cart, calculates free-order state through Core and delegates discovery to `PaymentOptionsFinder::present()`. This preserves Core payment discovery and `actionPresentPaymentOptions`.

The payment template preserves option IDs/module names, binary markers, actions, inputs, additional information and module forms. Ordinary values are escaped. `displayPaymentTop`, `PaymentOption::additionalInformation` and module-provided forms are explicit trusted payment-module HTML boundaries.

During authoritative AJAX refreshes `PaymentSectionRenderer::renderWithSelections()` marks a radio checked only when the fresh presented module/option combination exactly matches the canonical persisted `module:option` selection. Normal rendering without server selections marks no payment option as selected.

`views/js/payment-controller.js` mounts re-entrantly on initial DOM and `jzopc:section:updated`. It removes/aborts old handlers, synchronizes related additional-information/payment-form containers, exposes the selected payment form, and publishes `jzopc:payment:selected` / `jzopc:payment:initialized`. It deliberately never calls `submit()`/`requestSubmit()`, preserving a separate final payment handoff boundary.

`CheckoutPaymentSelectionParser` accepts a bounded option ID + module contract. `CheckoutPaymentSelectionService` re-runs the Core-backed presenter and requires exact module-key, option-ID and presented-module agreement before producing a canonical `module:option` key.

`CheckoutPaymentSelectionMutation` now runs that parser and fresh validation inside the orchestrator critical section. After a valid payment change, it also regenerates the current agreement contract and preserves previously approved agreement keys only if they still equal the complete required set; otherwise approvals are cleared. It then renders every dependency-resolved section using the resulting canonical server selections and lets the orchestrator persist them. Invalid/unavailable payment choices return a structured validation failure without overwriting persisted state.

See `ADR-0004-server-validated-payment-selection.md` and `ADR-0006-server-side-checkout-selection-persistence.md`.

### Agreements

`AgreementsSectionRenderer` uses `PrestaShopCheckoutAgreementsPresenter`, which delegates discovery to Core `ConditionsToApproveFinder::getConditionsToApproveForTemplate()`. That preserves the configured shop terms and `termsAndConditions` hook output, including Core duplicate-identifier reduction/formatting semantics.

The module owns accessible native checkbox markup and escapes identifiers. The condition body itself is an explicit trusted Core/module formatted-HTML boundary and is never sourced from browser data. During authoritative refresh, only keys present in canonical `CheckoutServerSelections` are rendered checked.

`CheckoutAgreementSelectionParser` accepts only a bounded list of safe identifiers. `CheckoutAgreementSelectionService` regenerates the current Core condition set and accepts approval only when submitted keys exactly equal all currently required keys; omitted and forged keys both fail closed.

`CheckoutAgreementSelectionMutation` executes parser + exact-set validation inside the orchestrator critical section, merges only validated keys into the canonical server selection object, renders the dependency-resolved agreement section from that resulting state and lets the orchestrator persist the successful outcome. Invalid/incomplete approvals return a structured validation failure and keep the prior persisted selection state unchanged.

Final submission must still revalidate the fresh Core condition set immediately before payment/order handoff.

See `ADR-0005-server-validated-checkout-agreements.md` and `ADR-0006-server-side-checkout-selection-persistence.md`.

### Identity

Identity remains intentionally unregistered. Guest/account creation and login integration must preserve Core customer validation/business rules rather than ship a placeholder renderer.

## Rendering trust boundaries

Module-owned labels, identifiers and presented values are escaped by context. Raw HTML is limited to outputs defined by PrestaShop as module/Core markup:

- carrier extra/before/after hook HTML;
- payment top/additional information/forms;
- Core-formatted checkout legal conditions.

Those raw boundaries must never be widened to browser-controlled or arbitrary customer-stored HTML.

## Current refresh graph

The dependency resolver is conservative. For example address/cart changes refresh addresses, delivery, payment, agreements and summary; payment selection refreshes payment, agreements and summary; agreement changes refresh agreements. Correctness is preferred over micro-optimizing renders.

## Testing state

The smoke suite covers capability/activation logic, state/versioning, CSRF/cart binding, mutex/orchestrator behavior, selection-store/schema behavior, upgrade contract, response mapping, address selection/rendering, Core-backed address/delivery/payment/agreement presenters, payment JavaScript contract, payment selection validation and agreement exact-set validation.

It additionally verifies that payment/agreement rendering restores only server selections, that concrete endpoint source contracts route through the guarded mutation services, and that the common mutation controller preserves POST/405 behavior while active POSTs execute exactly once and inactive checkout POSTs fail closed with `checkout_unavailable`.

Orchestrator coverage verifies that selection loading/saving occurs under the cart lock, stale/failed/incomplete mutations do not persist, and a successful persisted payment selection changes the authoritative state version. GitHub Actions also validates Composer metadata/autoload, PHP 8.4 syntax and JavaScript syntax.

CI does **not** yet boot a real PrestaShop installation, run the `0.1.0 -> 0.2.0` upgrade against MySQL/MariaDB, exercise module front-controller routing through a live shop, or render Smarty in a real theme. Full Core service-container, database upgrade, theme, carrier/payment module and browser checkout integration remain required before production readiness.

## Next application boundary

The next highest-priority milestone is the browser-side mutation transport/bootstrap that sends payment/agreement selections with CSRF + cart/state-version bindings, applies the complete returned section set atomically, advances the authoritative state version and emits the existing section-update lifecycle events without duplicate handlers or stale-response overwrites.

That client must remain dormant until the version-specific checkout integration/provider/legacy adapter supplies the actual one-page checkout shell and endpoint/state bootstrap. The checkout activation gate must not be opened merely because the mutation routes now exist.

After the mutation client/integration boundary, Phase 5 must add final checkout validation, duplicate-order/idempotency protection, selection-row lifecycle cleanup and the native payment-module handoff. Carrier/address endpoints remain blocked on the module-owned Core checkout-session construction boundary described above.

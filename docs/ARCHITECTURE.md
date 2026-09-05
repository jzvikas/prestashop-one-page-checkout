# Architecture

This document describes architecture that exists in the repository today. It is updated as implementation milestones land and intentionally does not describe unfinished work as completed.

## Integration boundary and version strategy

`jzonepagecheckout.php` is a thin PrestaShop bootstrap. Runtime/version decisions live under `src/Integration`.

- PrestaShop 9.0/9.1 registers only `actionCheckoutRender`; the dedicated legacy adapter is not active yet, so Core checkout remains authoritative.
- PrestaShop 9.2+ registers only `actionCheckoutBuildProcess`; the module currently returns no custom provider, so Core/native checkout remains authoritative.
- `CheckoutActivationPolicy` blocks custom takeover when the native `ps_onepagecheckout` provider is enabled.
- unsupported capability combinations fail closed rather than referencing unavailable classes.

`JZOPC_CHECKOUT_ENABLED` is separate from module enabled state, starts disabled and is forced off when the module is disabled. The internal integration-readiness gate also remains false. There are no Core overrides. The module owns one small checkout-selection table introduced in version `0.2.0`; install/upgrade/uninstall lifecycle manages that schema.

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

Stale, CSRF-rejected, failed or incomplete mutations do not overwrite persisted selections. This prevents controllers from injecting their own notion of current payment/agreement state and makes persistence part of the same serialized transaction boundary as stale-state validation.

`CheckoutMutationResponseMapper` maps application results to stable HTTP semantics and the abstract front controller owns no-store JSON headers plus exception containment/logging. No concrete public checkout mutation endpoint is enabled yet.

## Section rendering

`CheckoutSectionRendererRegistry` is fail-closed: every requested section must have exactly one renderer. Missing/duplicate renderers are programming errors, not successful partial refreshes.

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

`views/js/payment-controller.js` mounts re-entrantly on initial DOM and `jzopc:section:updated`. It removes/aborts old handlers, synchronizes related additional-information/payment-form containers, exposes the selected payment form, and publishes `jzopc:payment:selected` / `jzopc:payment:initialized`. It deliberately never calls `submit()`/`requestSubmit()`, preserving a separate final payment handoff boundary.

`CheckoutPaymentSelectionParser` accepts a bounded option ID + module contract. `CheckoutPaymentSelectionService` re-runs the Core-backed presenter and requires exact module-key, option-ID and presented-module agreement before producing a canonical `module:option` key. The service can merge that validated key into `CheckoutServerSelections` while preserving already validated agreements. The orchestrator can now persist that validated state safely, but a concrete public payment mutation endpoint is still intentionally absent.

See `ADR-0004-server-validated-payment-selection.md` and `ADR-0006-server-side-checkout-selection-persistence.md`.

### Agreements

`AgreementsSectionRenderer` uses `PrestaShopCheckoutAgreementsPresenter`, which delegates discovery to Core `ConditionsToApproveFinder::getConditionsToApproveForTemplate()`. That preserves the configured shop terms and `termsAndConditions` hook output, including Core duplicate-identifier reduction/formatting semantics.

The module owns accessible native checkbox markup and escapes identifiers. The condition body itself is an explicit trusted Core/module formatted-HTML boundary and is never sourced from browser data.

`CheckoutAgreementSelectionParser` accepts only a bounded list of safe identifiers. `CheckoutAgreementSelectionService` regenerates the current Core condition set and accepts approval only when submitted keys exactly equal all currently required keys; omitted and forged keys both fail closed. Validated agreements can be merged into `CheckoutServerSelections` while preserving the validated payment selection and can now be persisted by the orchestrator after successful mutation handling.

A public agreement mutation endpoint is still intentionally absent, and final submission must revalidate the fresh Core condition set immediately before payment/order handoff.

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

The smoke suite covers capability/activation logic, state/versioning, CSRF/cart binding, mutex/orchestrator behavior, selection-store/schema behavior, upgrade contract, response mapping, address selection/rendering, Core-backed address/delivery/payment/agreement presenters, payment JavaScript contract, payment selection validation and agreement exact-set validation. Orchestrator coverage verifies that selection loading/saving occurs under the cart lock, stale/failed/incomplete mutations do not persist, and a successful persisted payment selection changes the authoritative state version. GitHub Actions also validates Composer metadata/autoload, PHP 8.4 syntax and JavaScript syntax.

CI does **not** yet boot a real PrestaShop installation, run the `0.1.0 -> 0.2.0` upgrade against MySQL/MariaDB, or render Smarty in a live shop. Full Core service-container, database upgrade, theme, carrier/payment module and browser checkout integration remain required before production readiness.

## Next application boundary

The next highest-priority application milestone is to add concrete `PaymentSelected` and `AgreementsChanged` mutation handlers/endpoints that parse request data, execute the existing fresh Core validators inside `CheckoutMutationOrchestrator`, render the complete dependency-resolved section set and rely only on orchestrator-owned persisted selections. After that, Phase 5 must add final checkout validation, duplicate-order/idempotency protection, selection-row lifecycle cleanup and the native payment-module handoff. Carrier/address endpoints remain blocked on the module-owned Core checkout-session construction boundary described above.

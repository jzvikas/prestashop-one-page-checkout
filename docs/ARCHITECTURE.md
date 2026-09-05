# Architecture

This document describes architecture that exists in the repository today. It intentionally does not describe unfinished work as completed.

## Integration boundary and version strategy

`jzonepagecheckout.php` remains a thin PrestaShop bootstrap. Runtime/version decisions and checkout-process adapters live under `src/Integration`.

- PrestaShop 9.0/9.1 registers `actionCheckoutRender`. `LegacyCheckoutRenderAdapter` validates the Core process supplied by the reference-bearing hook, reuses its current `CheckoutSession`, and replaces only the process object with the module-built process.
- PrestaShop 9.2+ registers `actionCheckoutBuildProcess`. `Integration/Provider/CheckoutProcessProvider` implements the exact `CheckoutProcessProviderInterface` contract and delegates to the same module process builder.
- The 9.2-only provider class is not loaded on older 9.x runtimes: module code checks `interface_exists()` before it resolves the provider class.
- `CheckoutActivationPolicy` blocks custom takeover when native `ps_onepagecheckout` is enabled and fails closed on unsupported capability combinations.
- `INTEGRATION_SHELL_READY` remains `false`, so neither adapter can currently take over live checkout. Public mutation controllers therefore still return `checkout_unavailable`.

`PrestaShopRuntimeProbe` asks whether Core capabilities are autoloadable, not merely whether their classes have already been touched in the current process. Installed-runtime CI caught and fixed a false-negative caused by `class_exists('Hook', false)`: clean PrestaShop CLI processes legitimately had not loaded `Hook` yet, so normal autoload-capable `class_exists()` is required for capability detection.

`CheckoutProcessBuilder` creates a real Core `CheckoutProcess` around a single `CheckoutShellStep`. The step extends `AbstractCheckoutStep`, stays reachable/current as the one-page surface and calls Core `renderTemplate()`, preserving `actionCheckoutStepRenderTemplate`. `CheckoutShellRenderer` then renders the trusted module root from the same Core cart/session and persisted server selections used by mutation guards.

`CheckoutBrowserBootstrapFactory` builds the initial browser binding from the loaded cart, `Tools::getToken(false)`, `PrestaShopCheckoutStateFactory`, `CheckoutStateVersioner` and PrestaShop-generated module links. The shell exposes only cart ID, CSRF token, state version and saved-address/address-form/carrier/payment/agreement endpoint URLs. It does not expose client-authoritative totals or a browser copy of server selections.

`actionFrontControllerSetMedia` is installed in addition to the version-specific checkout hook. It is restricted to `OrderController`, must pass the same activation policy, and delegates to `CheckoutFrontendAssetRegistrar`. Existing installations receive that hook through idempotent `upgrade/upgrade-0.3.0.php`; the version is therefore `0.3.0`.

See ADR-0001, ADR-0008, ADR-0009 and ADR-0010.

## Core CheckoutSession boundary

`CheckoutSessionProviderInterface` is the single application boundary for Core delivery/address session behavior.

`PrestaShopCheckoutSessionProvider` first reuses `Context::controller->getCheckoutSession()` when an active `OrderController`-compatible controller exposes it. Module mutation front controllers do not expose that method, so the provider has a Core-faithful construction fallback:

- PrestaShop 9.0 stays on legacy `DeliveryOptionsFinder`;
- PrestaShop 9.1+ may use `PrestaShop\PrestaShop\Adapter\Shipment\DeliveryOptionsProvider` only when that class exists, the `FEATURE_FLAG_IMPROVED_SHIPMENT` constant exists and Core's `FeatureFlagStateCheckerInterface` says the flag is enabled;
- the 9.1+ provider is stored as a class-name string and instantiated dynamically so PrestaShop 9.0 never acquires a hard reference to a class that does not exist there;
- Object/Cart presenters are reused from a compatible front controller when available, otherwise Core presenter instances are constructed from the active `Context`.

This boundary is shared by delivery rendering, saved-address selection, Core-backed address persistence and carrier selection. See ADR-0013, ADR-0014 and ADR-0015.

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
5. resolve required refreshed sections for the current cart context;
6. execute the operation handler with guarded state and server-loaded selections;
7. reject successful output missing required sections;
8. persist new selections only for a structurally complete successful outcome;
9. rebuild server-authoritative state/version from Core plus resulting selections;
10. release the mutex in `finally`.

Stale, CSRF-rejected, failed or incomplete mutations do not overwrite persisted selections.

`CheckoutSectionDependencyResolver` is context-aware when called by the orchestrator. Virtual carts remove `delivery` from the required refresh set because their shell intentionally has no delivery section and `DeliverySectionRenderer` returns no HTML. Address-editor-only refreshes touch only `addresses`; a successful address persistence refreshes addresses plus every downstream eligibility/totals section and invalidates payment/agreement authority. Context-free resolver calls stay conservative for static dependency inspection. A future cart mutation that can change virtual/physical topology must add an explicit section insert/remove contract rather than assuming replacement-only DOM semantics.

`CheckoutMutationResponseMapper` maps application results to stable HTTP semantics. `JzOnePageCheckoutAbstractJsonModuleFrontController` owns no-store JSON headers plus exception containment/logging, while `JzOnePageCheckoutAbstractMutationModuleFrontController` owns POST-only transport and the fail-closed activation gate.

Concrete mutation controllers currently exist for `addressselection`, `addresssave`, `carrierselection`, `paymentselection` and `agreements`. Controllers collect request values but do not authorize carts, prices, addresses, carrier options, modules or agreements. State-sensitive parsing and validation run inside `CheckoutMutationOrchestrator`, after mutex acquisition and fresh-state validation.

## Browser mutation transport

`views/js/checkout-mutation-client.js` is the shared browser transport for the implemented address/carrier/payment/agreement endpoints. It activates only inside the trusted module-owned `[data-jzopc-checkout]` root with positive cart ID, non-empty CSRF/state tokens and explicit endpoint URLs.

Every mutation sends the trusted bootstrap `token`, `cartId`, prior `stateVersion` and only operation-specific data. It never sends client-calculated money or a browser copy of `CheckoutServerSelections`. The reserved names `token`, `cartId` and `stateVersion` cannot be overwritten by serialized native/theme/module form fields.

Saved-address radio/checkbox changes are delegated from the stable checkout root. Delivery address, invoice mode and optional separate invoice address are serialized into one address intent and sent to one endpoint, avoiding independent requests that could race against each other. Missing separate-invoice input is intentionally left for the server parser to reject with a translated canonical error rather than fabricating untranslated browser validation text.

Address create/edit is opened through the guarded `addresssave` endpoint. The server renders the active theme's native Core `CustomerAddressForm`. The client serializes the native form fields rather than maintaining a second address schema. An `id_country` change re-presents that form through the same guarded stale-safe transport, causing Core `CustomerAddressFormatter` to regenerate country/state-dependent fields without persisting the address. Native form hidden fields cannot replace the trusted mutation binding.

Delivery-option radio changes are delegated from the same stable root. The browser sends only the opaque Core delivery-option key to the trusted carrier endpoint. It does not send a carrier price, label, id list or calculated total. The server revalidates the key against the fresh Core session before any cart change.

A newer mutation increments a local sequence and aborts the prior request where `AbortController` is available; every response is also compared with the latest sequence. A slower old response therefore cannot overwrite newer checkout state even if cancellation races or is unavailable.

A `409 stale_state` response with `retryable=true` and a current server version may advance the local token and replay the same latest intent exactly once. Other retryable failures are not automatically replayed.

Before changing DOM, the client validates the complete returned section map. Every response key must map to a current section and returned HTML must contain exactly one matching `data-jzopc-section` root. If any replacement is invalid, none is applied. Successful application updates the root state version and emits `jzopc:section:updated` for each replaced section.

The client publishes checkout lifecycle events including `jzopc:address:selected` and `jzopc:carrier:selected`, but does not submit payment forms or place orders.

See ADR-0007, ADR-0008, ADR-0013, ADR-0014 and ADR-0015.

## Section rendering

`CheckoutSectionRendererRegistry` is fail-closed: every requested section must have exactly one renderer. Missing/duplicate renderers are programming errors, not successful partial refreshes. It passes canonical `CheckoutServerSelections` only to state-aware renderers.

### Summary

`SummarySectionRenderer` uses `PrestaShopCheckoutCartPresenter`, delegating to Core `CartPresenter` and preserving `actionPresentCart`.

### Addresses

`AddressesSectionRenderer` uses `PrestaShopCheckoutAddressBookPresenter`. It reads only the cart-bound customer, fails on cart/customer mismatch, filters addresses through `Customer::customerHasAddress()`, loads Core `Address` objects and formats them with `AddressFormat::generateAddress()`.

Saved-address selection has a public guarded `addressselection` mutation path. `CheckoutAddressSelectionParser` normalizes delivery/invoice/same-address intent and rejects malformed/ambiguous inputs. `CheckoutAddressSelectionService` validates every submitted target against the cart-bound customer and applies changes through Core `CheckoutSession::setIdAddressDelivery()` / `setIdAddressInvoice()`. This preserves `Cart::updateAddressId()` delivery associations and reuses Core's linked delivery/invoice side effects rather than editing only cart header IDs.

Address creation/editing now has a separate guarded `addresssave` path. `CheckoutAddressFormService` builds the same Core `CustomerAddressForm`, `CustomerAddressFormatter` and `CustomerAddressPersister` stack used by PrestaShop front controllers. Existing edit targets are pre-authorized with `Customer::customerHasAddress()` before loading. Core's inner persister token is regenerated server-side, and successful persistence selects the resulting delivery/invoice address through `CheckoutSession`. The active theme's native `customer/_partials/address-form.tpl` output is rendered inside the address section so Core country/state/required-field rules and module-added address fields are not duplicated by the module.

A successful address save clears prior persisted payment/agreement selections and refreshes address, carrier/payment/legal and summary state. Invalid Core form submissions are returned with the native form's field errors. Merely opening the editor or changing country refreshes only the address section and does not persist business state.

Anonymous carts still fail closed until a real cart-bound guest/customer identity exists. Guest/account creation and login integration remain unfinished. See ADR-0013 and ADR-0015.

### Delivery

`DeliverySectionRenderer` uses `PrestaShopCheckoutDeliveryOptionsPresenter`. Physical carts execute `actionCarrierProcess` before discovery and obtain the active Core checkout session through `CheckoutSessionProviderInterface`. This preserves Core delivery keys, pricing/delay presentation, `displayCarrierExtraContent`, `displayBeforeCarrier` and `displayAfterCarrier`. Virtual carts emit no shipping section.

Carrier selection has a guarded `carrierselection` path. `CheckoutCarrierSelectionParser` accepts only a bounded delivery-option-key syntax. `CheckoutCarrierSelectionService` obtains the fresh Core `CheckoutSession::getDeliveryOptions()` set, requires an exact key match and applies a real change through `CheckoutSession::setDeliveryOption()`. An already-selected option is idempotent. Forged/stale options and virtual-cart carrier mutations fail closed.

A real carrier change clears prior persisted payment/agreement selections and refreshes delivery, payment, agreements and summary because shipping can change totals, payment eligibility and module-provided requirements. The browser uses the same stale-safe delegated mutation transport, so delivery-section replacement does not require rebinding listeners.

See ADR-0014.

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

Module-owned labels, identifiers and presented values are escaped by context. Raw HTML is limited to PrestaShop-defined Core/module markup: carrier extra/before/after hook HTML, payment top/additional-information/forms, Core-formatted checkout legal conditions, the native Core/theme address form, and section HTML already rendered by those trusted server renderers into the module shell.

The native address form may include markup contributed by `additionalCustomerAddressFields` and the active theme. It is server-rendered trusted Core/theme/module HTML; request data is passed through Core form fields/escaping and may never directly populate a new raw-HTML boundary.

Those raw boundaries must never be widened to browser-controlled or arbitrary customer-stored HTML.

## Refresh graph

The dependency resolver is conservative. Address selection/persistence and cart changes refresh addresses, delivery (physical carts only), payment, agreements and summary; address-editor presentation refreshes addresses only; carrier selection refreshes delivery, payment, agreements and summary; payment selection refreshes payment, agreements and summary; agreement changes refresh agreements. Correctness is preferred over micro-optimizing renders.

Address persistence and carrier business-state changes invalidate prior payment/agreement authority. Idempotent saved-address/carrier selections preserve current validated selections; address form presentation does not mutate selections.

## Testing state

The smoke suite contains coverage for capability/activation logic, state/versioning, CSRF/cart binding, mutex/orchestrator behavior, selection-store/schema behavior, upgrade contracts, response mapping, Core-backed address/delivery/payment/agreement presenters, Core-session saved-address semantics, Core-backed address-form source contracts, carrier-option validation/idempotency, payment JavaScript behavior, payment selection validation, agreement exact-set validation, authoritative selection restoration, guarded mutation endpoint contracts, virtual-cart dependency filtering and stale-safe browser mutation transport.

Version-specific source-contract coverage also verifies the 9.2 provider shape/isolation, 9.0/9.1 Core-session reuse, module shell step construction, Core `renderTemplate()` lifecycle, readiness gate, media registration and `0.3.0` upgrade path.

GitHub Actions baseline CI is designed to validate Composer metadata and production autoload installation, PHP 8.4 syntax, JavaScript syntax with Node.js 22 and the full smoke suite.

The separate `PrestaShop Runtime` workflow provisions MariaDB 11.4 and source-tree installations for PrestaShop 9.1.5 and 9.2.0-beta.1. It contains contracts for module installation/capability/conflict behavior, real Core process/session adapters, installed Smarty shell rendering and a module-front `CheckoutSession` fallback. The Smarty shell contract now also requires the server-generated `addresssave` URL.

The address-form changes in this milestone have not received executable PHP/Node/smoke/runtime verification because GitHub Actions quota is exhausted and this execution environment cannot clone the repository from GitHub. The tests/contracts were updated normally and remain required after quota reset; no unexecuted check is recorded as passing. Focused local carrier checks from the preceding milestone remain recorded in ADR-0014.

The runtime suite still does not cover PrestaShop 9.0, live HTTP/browser navigation, active provider/reference-hook takeover with readiness open, representative carrier/payment modules, no-carrier transitions, native address add/edit interaction, identity or final order placement. Schema/media-hook upgrade execution against an older installed module version also remains to be added.

## Next application boundary

Saved-address selection, Core-backed address add/edit and carrier selection are now wired through the production mutation architecture, but activation remains deliberately fail-closed. The next highest-priority application work is:

1. implement guest/logged-in identity creation/login/update through PrestaShop Core customer forms/persisters so anonymous carts can enter the address flow safely;
2. after Actions quota reset, execute all deferred smoke/runtime contracts and fix every failure before using those results to reconsider checkout readiness;
3. build the controlled live HTTP/browser harness proving native fallback, shell/assets, native address interaction, stale-safe mutations, no-carrier behavior and representative payment/carrier lifecycle;
4. implement Phase 5 final validation, duplicate-order/idempotency protection, selection cleanup and native payment-module handoff;
5. only after those gates, add Back Office activation/rollout controls and reconsider `INTEGRATION_SHELL_READY`.

`INTEGRATION_SHELL_READY` must remain `false` until those safety gates justify production takeover.

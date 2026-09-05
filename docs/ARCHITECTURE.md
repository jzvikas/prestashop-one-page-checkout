# Architecture

This document describes architecture that exists in the repository today. It intentionally does not describe unfinished work as completed.

## Integration boundary and version strategy

`jzonepagecheckout.php` remains a thin PrestaShop bootstrap. Runtime/version decisions and checkout-process adapters live under `src/Integration`.

- PrestaShop 9.0/9.1 registers `actionCheckoutRender`. `LegacyCheckoutRenderAdapter` validates the Core process supplied by the reference-bearing hook, reuses its current `CheckoutSession`, and replaces only the process object with the module-built process.
- PrestaShop 9.2+ registers `actionCheckoutBuildProcess`. `Integration/Provider/CheckoutProcessProvider` implements the exact `CheckoutProcessProviderInterface` contract and delegates to the same module process builder.
- The 9.2-only provider class is not loaded on older 9.x runtimes: generic module code checks `interface_exists()` before resolving the provider class.
- `CheckoutActivationPolicy` blocks custom takeover when native `ps_onepagecheckout` is enabled and fails closed on unsupported capability combinations.
- `INTEGRATION_SHELL_READY` remains `false`, so neither adapter can currently take over live checkout. Public mutation controllers therefore still return `checkout_unavailable` in normal traffic.

`PrestaShopRuntimeProbe` asks whether Core capabilities are autoloadable, not merely whether their classes have already been touched in the current process. Installed-runtime CI previously caught and fixed a false-negative caused by non-autoloading class checks.

`CheckoutProcessBuilder` creates a real Core `CheckoutProcess` around a single `CheckoutShellStep`. The step extends `AbstractCheckoutStep`, stays reachable/current as the one-page surface and calls Core `renderTemplate()`, preserving `actionCheckoutStepRenderTemplate`. `CheckoutShellRenderer` renders the trusted module root from the same Core cart/session and persisted server selections used by mutation guards.

`CheckoutBrowserBootstrapFactory` builds the initial browser binding from the loaded cart, `Tools::getToken(false)`, `PrestaShopCheckoutStateFactory`, `CheckoutStateVersioner` and PrestaShop-generated module links. The shell exposes only cart ID, CSRF token, state version and identity/address/address-form/carrier/payment/agreement endpoint URLs. It does not expose client-authoritative totals or a browser copy of server selections.

`actionFrontControllerSetMedia` is installed in addition to the version-specific checkout hook. It is restricted to `OrderController`, must pass the same activation policy, and delegates to `CheckoutFrontendAssetRegistrar`. Existing installations receive that hook through idempotent `upgrade/upgrade-0.3.0.php`.

See ADR-0001, ADR-0008, ADR-0009, ADR-0010, ADR-0011 and ADR-0012.

## Core CheckoutSession boundary

`CheckoutSessionProviderInterface` is the single application boundary for Core delivery/address session behavior.

`PrestaShopCheckoutSessionProvider` first reuses `Context::controller->getCheckoutSession()` when an active `OrderController`-compatible controller exposes it. Module mutation front controllers do not expose that method, so the provider has a Core-faithful construction fallback:

- PrestaShop 9.0 stays on legacy `DeliveryOptionsFinder`;
- PrestaShop 9.1+ may use `PrestaShop\PrestaShop\Adapter\Shipment\DeliveryOptionsProvider` only when that class exists, the `FEATURE_FLAG_IMPROVED_SHIPMENT` constant exists and Core's feature-flag checker says the flag is enabled;
- the 9.1+ provider is referenced dynamically so PrestaShop 9.0 never acquires a hard class dependency;
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

`CheckoutSectionDependencyResolver` is context-aware when called by the orchestrator. Virtual carts remove `delivery` from required refreshes because their shell intentionally has no delivery section. Identity changes refresh the entire canonical checkout because customer groups, cart binding, addresses, rules, carrier/payment eligibility and totals can change. Address-editor-only refreshes touch only `addresses`; successful address persistence refreshes addresses plus every downstream eligibility/totals section. Carrier selection refreshes delivery, payment, agreements and summary. Payment selection refreshes payment, agreements and summary. Agreement changes refresh agreements.

`CheckoutMutationResponseMapper` maps application results to stable HTTP semantics. It can attach a fresh CSRF token only to a mutation that reached `CheckoutMutationExecutionStatus::Completed`; rejected guard responses never receive replacement CSRF material. `JzOnePageCheckoutAbstractJsonModuleFrontController` owns no-store JSON headers plus exception containment/logging, while `JzOnePageCheckoutAbstractMutationModuleFrontController` owns POST-only transport and the fail-closed activation gate.

Concrete mutation controllers currently exist for `identity`, `addressselection`, `addresssave`, `carrierselection`, `paymentselection` and `agreements`. Controllers collect request values but do not authorize carts, prices, addresses, carrier options, modules or agreements. State-sensitive parsing and validation run inside `CheckoutMutationOrchestrator`, after mutex acquisition and fresh-state validation.

## Identity and authentication boundary

`CheckoutIdentityService` is a thin adapter over PrestaShop Core identity primitives rather than a module-owned customer implementation.

For anonymous checkout it constructs:

- `CustomerFormatter` + `CustomerForm`;
- `CustomerPersister` using the Core `hashing` service;
- `CustomerLoginFormatter` + `CustomerLoginForm`.

The formatter follows Core partner-optin/birthdate requirements and the form follows `PS_GUEST_CHECKOUT_ENABLED`. Create/guest submission preserves `actionSubmitAccountBefore`; actual validation and persistence run through `CustomerForm::submit()`. Authentication runs through `CustomerLoginForm::submit()`. The module never hashes a customer password itself and does not use direct `Customer::add()` persistence for this flow.

Anonymous Core forms are rendered by the active theme and may contain module-added fields. On validation failure, already-submitted form instances are rendered once and passed to `IdentitySectionRenderer`; the mutation deliberately omits identity from the generic second render pass so form/module hooks are not executed twice merely to show errors.

After successful Core submission, the service requires a positive context customer ID equal to the current cart customer ID. The normal identity transition clears server-persisted payment/agreement authority and refreshes all checkout sections.

### CSRF rotation

Core customer/session changes can alter `Tools::getToken(false)`. The `identity` controller therefore obtains a new token only after the guarded mutation has reached `Completed`. The response mapper attaches it to completed normal/validation responses. The browser updates both its in-memory token and `data-jzopc-csrf-token` before any next request. Invalid-CSRF/rejected requests do not receive replacement token material.

### `PS_CART_FOLLOWING` cart restoration

`Context::updateCustomer()` can restore another non-ordered customer cart under Core `PS_CART_FOLLOWING` behavior. The identity request mutex protects the cart that started the request, not an arbitrary replacement cart selected inside Core.

`CheckoutIdentityMutation` compares the post-submit cart ID with the guarded initial state's cart ID. If Core switched carts, the mutation returns a completed failure outcome with no rendered section set and does not save replacement-cart module selection state. The identity controller recognizes the cart ID transition and returns a successful redirect-only transport response to the Core order page, including the fresh state version and CSRF token. The browser performs a full reload, and the replacement cart receives a new authoritative bootstrap before any later write.

The module does not disable, copy or reimplement Core cart-following behavior.

See ADR-0016.

## Browser mutation transport

`views/js/checkout-mutation-client.js` is the shared browser transport for identity/address/carrier/payment/agreement endpoints. It activates only inside the trusted module-owned `[data-jzopc-checkout]` root with positive cart ID, non-empty CSRF/state tokens and all explicit server-generated endpoint URLs.

Every mutation sends the trusted bootstrap `token`, `cartId`, prior `stateVersion` and only operation-specific data. It never sends client-calculated money or a browser copy of `CheckoutServerSelections`. The reserved names `token`, `cartId` and `stateVersion` cannot be overwritten by serialized native/theme/module forms.

Identity Core forms are intercepted through delegated `submit` handling under `[data-jzopc-identity-form]`, serialized with `FormData`, tagged only with `identityAction=create|login`, and sent to the trusted identity URL. Password fields remain ordinary request fields to Core; the browser transport does not log or publish their values in lifecycle event details. A completed response may rotate the CSRF token. A cart-restoration response contains no old-cart section replacements and redirects immediately to a fresh order-page bootstrap.

Saved-address radio/checkbox changes are delegated from the stable checkout root. Delivery address, invoice mode and optional separate invoice address are serialized into one address intent. Address create/edit is opened through guarded `addresssave`; country changes re-present the native form through the same stale-safe channel. Delivery-option radio changes send only the opaque Core option key.

A newer mutation increments a local sequence and aborts the prior request where `AbortController` is available; every response is also compared with the latest sequence. A slower old response therefore cannot overwrite newer checkout state even when cancellation races or is unavailable.

A `409 stale_state` response with `retryable=true` and a current server version may advance the local token and replay the same latest intent exactly once. Other retryable failures are not automatically replayed.

Before changing DOM, the client validates the complete returned section map. Every response key must map to a current section and returned HTML must contain exactly one matching `data-jzopc-section` root. If any replacement is invalid, none is applied. Successful application updates state/optional CSRF, replaces the validated set and emits `jzopc:section:updated` for each section.

The client publishes lifecycle events including identity/address/carrier/checkout updates but does not submit a selected payment form or place an order.

See ADR-0007, ADR-0008, ADR-0013, ADR-0014, ADR-0015 and ADR-0016.

## Section rendering

`CheckoutSectionRendererRegistry` is fail-closed: every requested section must have exactly one renderer. Missing/duplicate renderers are programming errors, not successful partial refreshes. It passes canonical `CheckoutServerSelections` only to state-aware renderers.

### Identity

`IdentitySectionRenderer` delegates presentation to `CheckoutIdentityService` and owns only `CheckoutSection::Identity`.

Anonymous state renders the active theme's native Core customer and login forms inside module-owned wrappers. Bound state renders module-owned escaped first name, last name and email plus a guest/signed-in status. Invalid submissions reuse the already-rendered Core forms containing authoritative field errors.

### Summary

`SummarySectionRenderer` uses `PrestaShopCheckoutCartPresenter`, delegating to Core `CartPresenter` and preserving `actionPresentCart`.

### Addresses

`AddressesSectionRenderer` uses `PrestaShopCheckoutAddressBookPresenter`. It reads only the cart-bound customer, fails on cart/customer mismatch, filters addresses through `Customer::customerHasAddress()`, loads Core `Address` objects and formats them with `AddressFormat::generateAddress()`.

Saved-address selection uses guarded `addressselection`. `CheckoutAddressSelectionService` applies authorized targets through Core `CheckoutSession::setIdAddressDelivery()` / `setIdAddressInvoice()` so `Cart::updateAddressId()` side effects are preserved.

Address creation/editing uses guarded `addresssave`. `CheckoutAddressFormService` builds Core `CustomerAddressForm`, `CustomerAddressFormatter` and `CustomerAddressPersister`; existing edit targets are authorized before loading, the Core persister token is regenerated server-side, and successful persistence selects the resulting address through `CheckoutSession`. The active theme's native address form is preserved.

A successful address save clears prior payment/agreement authority and refreshes addresses plus downstream checkout state. Merely opening/re-presenting the editor does not persist business state.

### Delivery

`DeliverySectionRenderer` uses `PrestaShopCheckoutDeliveryOptionsPresenter`. Physical carts execute `actionCarrierProcess`, use the active Core checkout session and preserve Core delivery keys plus `displayCarrierExtraContent`, `displayBeforeCarrier` and `displayAfterCarrier`. Virtual carts emit no shipping section.

Carrier selection uses guarded `carrierselection`. `CheckoutCarrierSelectionService` requires a cart-bound customer/authorized delivery address, obtains fresh Core delivery options, requires an exact key match and applies a real change through `CheckoutSession::setDeliveryOption()` with Core's native address-keyed payload. Persisted cart state is rechecked after the write. A real change clears payment/agreement authority.

### Payment

`PaymentSectionRenderer` delegates discovery to Core `PaymentOptionsFinder::present()`, preserving payment discovery and `actionPresentPaymentOptions`. The template preserves option IDs/module names, binary markers, actions, inputs, additional information and module forms.

`views/js/payment-controller.js` mounts re-entrantly on initial DOM and `jzopc:section:updated`, synchronizes related additional-information/payment-form containers and publishes payment lifecycle events. It deliberately never calls `submit()` or `requestSubmit()`.

`CheckoutPaymentSelectionService` regenerates the current Core-backed option set and requires exact module key, option ID and presented module-name agreement. A validated selection is eligibility state only, not final order authorization.

### Agreements

`AgreementsSectionRenderer` delegates discovery to Core `ConditionsToApproveFinder::getConditionsToApproveForTemplate()`, preserving configured shop terms plus `termsAndConditions` hook output.

The module owns accessible checkbox markup and escapes identifiers. `CheckoutAgreementSelectionService` regenerates the current set and accepts approval only when submitted keys exactly equal all current required keys. Final submission must revalidate them again immediately before handoff.

## Rendering trust boundaries

Module-owned labels, identifiers and presented values are escaped by context. Raw HTML is limited to PrestaShop-defined server-rendered Core/theme/module markup:

- native Core/theme customer and login forms, including module-added customer fields;
- native Core/theme address form, including module-added address fields;
- carrier extra/before/after hook HTML;
- payment top/additional-information/forms;
- Core-formatted checkout legal conditions;
- section HTML already produced by these trusted server renderers when composed into the module shell.

Request strings are passed into Core forms/validators; they are never concatenated directly into a new raw HTML boundary by module code. These boundaries must not be widened to arbitrary browser-controlled or customer-stored HTML.

## Refresh graph

The dependency resolver is conservative:

- identity update: identity, addresses, delivery for physical carts, payment, agreements, summary;
- address selection/persistence and cart changes: addresses, delivery for physical carts, payment, agreements, summary;
- address-editor presentation/country refresh: addresses only;
- carrier selection: delivery for physical carts, payment, agreements, summary;
- payment selection: payment, agreements, summary;
- agreement changes: agreements.

Identity, address persistence and real carrier changes invalidate prior payment/agreement authority. Idempotent saved-address/carrier selections preserve validated selections where the current server state remains identical.

## Testing state

The smoke suite contains source/domain coverage for capability/activation logic, state/versioning, CSRF/cart binding, mutex/orchestrator behavior, selection-store/schema behavior, upgrade contracts, response mapping, Core-backed identity/address/delivery/payment/agreement presenters/services, carrier/payment/agreement validation, section dependencies, authoritative selection restoration and stale-safe browser transport.

Identity-specific contracts require the Core customer/login form stack, shared service/container wiring, guarded endpoint, full refresh dependencies, trusted bootstrap URL, delegated form serialization, CSRF rotation, validation-form reuse and the `PS_CART_FOLLOWING` cart-restoration reload boundary.

The installed-runtime workflow provisions MariaDB 11.4 and PrestaShop 9.1.5 / 9.2.0-beta.1. `InstalledSmartyShellContract.php` now also requires identity section markup, both anonymous Core identity forms and the server-generated identity URL.

The latest identity/address/carrier source, Node, smoke and installed-runtime checks have **not** been executed in this delta because the repository's GitHub Actions free quota is exhausted and this execution environment does not provide a local repository/runtime. Test code remains committed normally; no deferred check is recorded as passing.

The runtime suite still lacks PrestaShop 9.0 coverage, live HTTP/browser navigation, active provider/reference-hook takeover with readiness open, representative carrier/payment modules, no-carrier transitions, full identity guest/account/login interaction, native address interaction and final order placement.

## Next application boundary

Core-backed identity now closes the Phase 2 anonymous-customer blocker in source architecture, while activation remains deliberately fail-closed. Highest-priority remaining work is:

1. execute all deferred PHP/Node/smoke/installed-runtime contracts after the Actions quota resets and fix every failure before using those results as readiness evidence;
2. build the controlled live HTTP/browser harness proving native fallback, shell/assets, guest/account/login, CSRF rotation/cart restoration, native address interaction, stale-safe mutations, no-carrier behavior and representative payment/carrier lifecycle;
3. implement Phase 5 final checkout validation, duplicate-order/idempotency protection, successful-order selection cleanup and native payment-module handoff;
4. only after those gates, add Back Office activation/rollout controls and reconsider `INTEGRATION_SHELL_READY`.

`INTEGRATION_SHELL_READY` must remain `false` until those safety gates justify production takeover.

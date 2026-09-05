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

## AJAX mutation security boundary

`CheckoutMutationGuard` is the shared pre-handler gate for future state-changing AJAX operations. It validates the PrestaShop front-office CSRF token, asserts that the submitted cart ID matches the cart already loaded by the current session/context, verifies cart/customer binding, rebuilds the current server state and rejects stale state versions before a mutation handler can run.

The client cart ID is never used to load a cart. Address/carrier/payment authorization remains operation-specific and must run after this generic gate. See `docs/SECURITY.md`.

## Per-cart mutation serialization

`CheckoutCartMutex` uses MySQL/MariaDB connection-owned advisory locks through PrestaShop's Doctrine DBAL connection. Queries use bound parameters and the mutex fails closed when acquisition is unavailable. A mutation orchestrator acquires this mutex before evaluating `CheckoutMutationGuard`; the state check and write therefore occur in one critical section.

The lock scope includes database name/table prefix and cart ID, allowing separate shops/installations on the same database server to avoid accidental lock-name collision. No custom table or Core override is required.

## Mutation orchestration

`CheckoutMutationOrchestrator` defines one ordering for all future state-changing handlers:

1. reject an invalid CSRF token cheaply before lock acquisition;
2. acquire the server-side cart mutex;
3. repeat the complete mutation guard inside the critical section;
4. resolve all affected sections from `CheckoutSectionDependencyResolver`;
5. execute the operation handler with guarded server state and the required section list;
6. reject successful handler output that omitted any required section;
7. rebuild the server-authoritative checkout state after the operation and issue the new state version;
8. return the stable `CheckoutRefreshResult` contract before releasing the mutex.

Unexpected handler/programming exceptions deliberately propagate to the transport/controller boundary for safe server logging and generic customer errors. Lock contention is converted into a distinct busy result without executing the handler.

## JSON/HTTP transport boundary

`CheckoutMutationResponseMapper` maps application results to stable HTTP semantics: successful updates use 200, business validation failures 422, malformed cart binding 400, authorization/CSRF failures 403, and stale/busy conflicts 409. Stale responses contain only the fresh opaque state version, not the internal state payload. Busy/stale responses are marked retryable.

The abstract JSON controller owns no-store JSON headers, exception containment and server-side contextual logging without returning exception details. The mutation controller subclass makes the POST gate final, so concrete mutation endpoints cannot bypass the method requirement accidentally.

Generic transport messages pass through the controller translation boundary. Operation-specific `CheckoutError` messages must already be translated by the handler/service that creates them.

## Address selection boundary

`CheckoutAddressSelectionParser` accepts a deliberately small request contract: optional delivery address, explicit same/separate invoice mode, and a required invoice address in separate mode. IDs are strict positive integers. Same-address mode rejects a client invoice ID and derives invoice identity on the server.

`CheckoutAddressSelectionService` authorizes every address with Core `Customer::customerHasAddress` against the cart owner, rechecks the current delivery address before mirroring it to invoice, performs at most one cart save, is idempotent when nothing changed, and restores the in-memory cart address IDs if persistence reports failure.

This service contains no rendering/transport logic and is intended to run only inside `CheckoutMutationOrchestrator`.

## Checkout section rendering

`CheckoutSectionRendererRegistry` is the fail-closed rendering dispatcher for mutation outcomes. A requested section must have exactly one registered renderer; duplicate registrations or missing renderers raise an exception instead of allowing a successful mutation to return a partially refreshed checkout.

Legacy/Core rendering dependencies are isolated behind focused presenter/renderer interfaces. The production cart presenter mirrors the native OPC approach: it resets product-related cart presentation caches, uses PrestaShop Core `CartPresenter`, requests separated gifts, and eagerly resolves products/subtotals/totals before rendering. Because Core `CartPresenter` is used directly, `actionPresentCart` remains part of the presentation lifecycle.

`SummarySectionRenderer` renders module-owned `sections/summary.tpl` markup through the `module:` Smarty resource so the checkout is not tied to Classic/Hummingbird DOM structure. The template is namespaced under `.jzopc-summary`, escapes presented labels/values and includes an `aria-live` summary region.

`AddressesSectionRenderer` uses `PrestaShopCheckoutAddressBookPresenter`, which reads only the cart-bound `Context` customer, fails closed on a cart/customer identity mismatch, filters each saved address through Core `Customer::customerHasAddress`, loads the Core `Address` object, and uses `AddressFormat::generateAddress()` so displayed lines follow the shop/country address format instead of a module-hardcoded field order. The Smarty template escapes aliases and every generated line, uses native radio/checkbox controls, and exposes separate delivery/invoice groups without depending on theme DOM.

The current address renderer intentionally covers selection of existing saved addresses only. New/edit address forms still belong to a later Phase 2 slice and must reuse PrestaShop address-field/country/state validation rather than duplicate it.

`DeliverySectionRenderer` uses `PrestaShopCheckoutDeliveryOptionsPresenter`. It requires the loaded server cart, skips shipping entirely for virtual carts, executes the native `actionCarrierProcess` lifecycle before discovery, and obtains the active Core checkout session through `CheckoutSessionProviderInterface`. This preserves the Core `DeliveryOptionsFinder` or improved-shipment `DeliveryOptionsProvider` selected by the active checkout controller, including carrier price/delay presentation and module-specific `displayCarrierExtraContent`. It also preserves `displayBeforeCarrier` and `displayAfterCarrier`.

The production `PrestaShopCheckoutSessionProvider` centralizes access to that Core session and fails closed if the active controller does not expose `getCheckoutSession()`. Delivery no longer reaches into the controller itself. This gives native order rendering and future module controllers one explicit session boundary, but it does not yet construct a session independently; a future AJAX checkout controller must expose a source-backed session created with the same runtime feature-flag logic as Core `OrderController` before address/carrier mutation endpoints are enabled.

The delivery template owns only the surrounding namespaced markup and native radio controls. Carrier names, delays, prices and option identifiers are escaped. `displayCarrierExtraContent`, `displayBeforeCarrier` and `displayAfterCarrier` are explicit trusted hook-HTML boundaries and are rendered with Smarty `nofilter`; they originate from PrestaShop's hook/module lifecycle rather than browser input.

`PaymentSectionRenderer` is now registered as the fourth concrete renderer. `PrestaShopCheckoutPaymentOptionsPresenter` requires a loaded server cart, determines free-order state from Core `Cart::getOrderTotal(true, Cart::BOTH)`, and delegates payment discovery to Core `PaymentOptionsFinder::present()`. That keeps legacy/modern payment option discovery and Core's `actionPresentPaymentOptions` presentation hook in the same lifecycle as native checkout rather than inventing a module-specific payment registry.

The payment template preserves the payment-option data Core modules rely on: generated option IDs, module names, binary markers, action URLs, hidden inputs, additional information and module-supplied forms. It also keeps Core-compatible `.payment-options`, `.payment-option`, `.js-additional-information` and `.js-payment-option-form` class hooks for the upcoming reinitialization layer. Ordinary option labels, IDs, action attributes and input values are escaped. `displayPaymentTop`, `additionalInformation` and module-supplied payment forms are explicit trusted payment-module HTML boundaries rendered with `nofilter`, matching the PrestaShop payment option contract rather than treating them as browser input.

Payment rendering is deliberately not equivalent to payment submission. No payment-selection mutation or final-submit endpoint is exposed yet, no browser-selected payment option is trusted, and the module does not call `PaymentModule::validateOrder`. The future payment mutation must validate the requested option against a fresh Core `PaymentOptionsFinder` result under the existing cart mutex/stale-state guard. The frontend must also implement a re-entrant payment initialization lifecycle before payment HTML is replaced through AJAX, including binary/self-submitting options and third-party JavaScript.

Identity and agreements remain intentionally unregistered until their implementations can preserve the relevant PrestaShop business rules and hook/module output.

The smoke suite validates renderer delegation, session-provider fail-closed behavior, production address/delivery/payment presenters, exact Core delivery-option key preservation, carrier hook ordering, virtual-cart behavior, free-payment discovery and preservation of payment module data. Full Smarty rendering and service-container wiring still require an actual PrestaShop runtime integration test; CI does not currently boot a PrestaShop installation and this limitation must not be confused with a passing integration test.

## Next application boundary

The next highest-priority milestone is the **payment interaction/reinitialization and secure payment-selection boundary**. It must add a small re-entrant frontend payment controller that can safely restore option/form behavior after section replacement, publish the required lifecycle events, prevent duplicate handler/submission binding, and preserve binary/self-submitting flows. In parallel, the server-side payment-selection operation must validate a requested payment option against a freshly generated Core option set inside `CheckoutMutationOrchestrator`; browser payment identifiers are never authoritative.

Before carrier/address mutation endpoints are exposed, the module-owned AJAX controller path also needs a source-backed Core checkout-session construction path that preserves the same improved-shipment feature-flag choice as native `OrderController`.

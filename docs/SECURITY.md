# Security review

This document tracks checkout-specific threats, implemented controls and release-blocking gaps. It must be updated as checkout integration and final submission are added.

## Trust boundary

The browser is untrusted. The loaded PrestaShop `Context`/`Cart` is the checkout identity boundary. A submitted cart ID is only a binding assertion and is never used to load another cart. Prices, taxes, discounts, shipping price, payable total, carrier/payment eligibility, selected server checkout state and required legal conditions are server-authoritative.

Passwords, CSRF/auth tokens, cookies/session identifiers, payment secrets and full customer/address payloads are sensitive and must not be logged.

## Implemented controls

### Transport and activation gates

Checkout mutations use a final shared module-front-controller gate. Requests must be `POST`; non-POST requests receive HTTP 405 with `Allow: POST`. Before a concrete mutation service can execute, the controller requires `JzOnePageCheckout::isCustomCheckoutActive()` to pass the same capability/native-conflict/configuration/integration-readiness policy used by checkout hooks. Inactive or incomplete custom checkout returns `checkout_unavailable` and performs no mutation.

Concrete controllers exist for `identity`, `addressselection`, `addresssave`, `carrierselection`, `paymentselection` and `agreements`. They collect request values, resolve narrowly exposed application services and delegate to the guarded orchestrator. They do not load another cart from request data and do not accept browser prices/totals as authority.

The trusted shell/bootstrap and both version-specific checkout process adapters exist, but `INTEGRATION_SHELL_READY` deliberately remains `false`. Consequently the 9.0/9.1 process adapter, 9.2+ provider, frontend asset hook and mutation endpoints remain unreachable in normal checkout traffic until the remaining runtime/browser/final-submit gates are proven.

PrestaShop 9.2-only provider code is isolated in a dedicated autoload path. Generic module code checks for the provider interface before resolving the provider class, preventing older 9.x runtimes from loading an unavailable interface. The 9.0/9.1 adapter reuses the existing Core `CheckoutSession` from the hook-provided process rather than constructing one from browser input.

### Trusted browser bootstrap

`CheckoutBrowserBootstrapFactory` derives initial browser state only from server-owned context: loaded cart, `Tools::getToken(false)`, `PrestaShopCheckoutStateFactory`, persisted validated selections and PrestaShop-generated module links. The shell exposes only cart ID, CSRF token, state version and identity/address/address-form/carrier/payment/agreement mutation endpoint URLs.

The browser client is dormant unless that complete module-owned root is present. Bootstrap does not contain client-authoritative totals, customer/address payloads, passwords, payment credentials or payment form data. The CSRF token is intentionally present in same-origin page markup for mutation requests and must never be logged.

### CSRF and cross-cart/customer binding

`CheckoutMutationGuard` requires the PrestaShop front-office token (`token`, with Core/theme-compatible `static_token` fallback), validates it with constant-time comparison, requires submitted cart ID to match the already loaded cart and verifies context customer identity when the cart is customer-bound.

All concrete identity/address/carrier/payment/agreement mutation services execute only through `CheckoutMutationOrchestrator`; final-submit work must use the same or a stronger boundary.

`views/js/checkout-mutation-client.js` reserves `token`, `cartId` and `stateVersion`. Serialized native/theme/module form fields with those names are dropped before operation data is sent, so an inner form field cannot replace the trusted outer OPC binding.

The native Core address form has a second, distinct persister token. `CheckoutAddressFormService` injects a fresh `Tools::getToken(true, $context)` server-side rather than trusting the browser for that authorization.

### Identity, guest checkout and authentication

`CheckoutIdentityService` deliberately delegates identity business rules to PrestaShop Core:

- guest/account form schema and validation: `CustomerFormatter` + `CustomerForm`;
- guest/account persistence and Core customer/cart/session side effects: `CustomerPersister`;
- authentication: `CustomerLoginFormatter` + `CustomerLoginForm`;
- guest permission: `PS_GUEST_CHECKOUT_ENABLED`;
- pre-create lifecycle: `actionSubmitAccountBefore`;
- password hashing: Core `hashing` service passed into `CustomerPersister`.

The module does not call `password_hash()`, write `Customer::$passwd`, or directly create a `Customer` through `ObjectModel::add()` in this flow. Duplicate-account checks, password policy, guest-account behavior and authentication remain Core-owned.

After a successful Core create/login operation, the service requires a positive context customer ID equal to the active cart's customer ID. A mismatched/incomplete binding fails closed. Bound identity presentation escapes first name, last name and email.

Anonymous identity forms are the active theme's native Core customer/login forms. They can contain module-added customer fields. These fragments are an explicit trusted server-side Core/theme/module HTML boundary; request strings are passed through Core form handling rather than concatenated into raw module HTML.

On Core validation failure, the already-submitted forms are rendered and reused. `CheckoutIdentityMutation` does not instantiate/render a second identity form stack only to show errors, reducing duplicate hook execution and preserving authoritative field errors.

A normal successful identity transition clears prior payment/agreement selection authority because customer group, rules, addresses, carrier/payment eligibility and legal requirements may change.

### CSRF rotation after identity transition

Customer creation/login can alter the front-office identity from which `Tools::getToken(false)` is derived. The browser must not continue with a stale anonymous token.

The identity controller requests a fresh Core token only when the guarded mutation reached `CheckoutMutationExecutionStatus::Completed`. `CheckoutMutationResponseMapper` may attach that token only to completed responses. Rejected guard requests, including invalid-CSRF requests, never receive replacement token material.

The browser validates an optional returned `csrfToken`, then updates both its in-memory token and `data-jzopc-csrf-token` before another mutation is sent. The token is not included in lifecycle-event details or browser logging.

### Core cart restoration after login

`Context::updateCustomer()` may restore another non-ordered customer cart under `PS_CART_FOLLOWING`. The current identity request holds the mutex for the cart that began the request, not for a different cart Core may restore during authentication.

`CheckoutIdentityMutation` compares the post-submit cart ID with the guarded initial state's cart ID. If the IDs differ, it returns a completed internal failure with no rendered sections and does not persist replacement-cart module selection state as a successful mutation under the old lock.

The identity controller recognizes the completed cart transition and returns a successful redirect-only transport response to Core's `order` page, with empty sections plus fresh state/token. The browser reloads. The replacement cart becomes authoritative only through the next server bootstrap, and later mutations acquire its own cart lock.

The module does not suppress `PS_CART_FOLLOWING`, merge carts itself or continue AJAX writes across the old/new cart boundary.

### Address ownership / IDOR and Core persistence

`CheckoutAddressSelectionParser` accepts only normalized positive delivery/invoice identifiers plus an explicit same-address boolean. Same-address mode rejects a browser-supplied invoice id instead of accepting ambiguous authority.

`CheckoutAddressSelectionService` authorizes every submitted target with Core `Customer::customerHasAddress(cart_customer_id, address_id)`. When same-address mode reuses an existing cart delivery address not supplied in the request, ownership is checked again before it can become the invoice target. A missing checkout customer fails closed.

Authorized saved-address changes are applied through Core `CheckoutSession::setIdAddressDelivery()` / `setIdAddressInvoice()`. Delivery changes therefore preserve Core `Cart::updateAddressId()` side effects for per-product/customization delivery associations rather than changing only cart header IDs.

Address add/edit uses Core `CustomerAddressForm`, `CustomerAddressFormatter` and `CustomerAddressPersister`. Before loading an edit target, `CheckoutAddressFormService` checks `Customer::customerHasAddress()` so foreign IDs fail inside the JSON boundary. Core owns country/state/required-field validation, address/module validation hooks and used-address historization. The service does not call `Address::save()` directly or write cart address headers directly.

Successful address persistence verifies resulting ownership, selects the result through Core checkout session and clears payment/agreement authority before downstream rendering. Merely opening/re-rendering the editor does not invalidate selections or persist an address.

Anonymous carts still cannot save an address before the Core identity flow has successfully bound a real guest/customer to the cart; this fails closed with `address_customer_required`.

### Carrier selection authorization

`CheckoutCarrierSelectionParser` accepts only a bounded opaque delivery-option-key format. Browser shipping price, carrier label, eligibility and payable total are ignored.

`CheckoutCarrierSelectionService` requires a loaded non-virtual cart, a real cart-bound customer and a customer-owned current delivery address. It obtains a fresh Core `CheckoutSession::getDeliveryOptions()` set and accepts only an exact available key.

A real selection is persisted through `CheckoutSession::setDeliveryOption()` using Core's address-keyed payload. Persisted `Cart::$delivery_option` is re-read to confirm Core retained the option. Core's auto-selected fallback is not treated as proof that a shopper choice was already persisted.

A real carrier change clears payment/agreement authority before rendering delivery, payment, agreements and summary. Virtual carts reject carrier mutation entirely.

### Stale state and same-cart races

Every guarded mutation requires prior `stateVersion`. `CheckoutCartMutex` serializes the cart critical section through parameterized DB advisory locks; the complete guard/state check then runs inside that lock before mutation. This prevents two requests from both validating the same old state and serializing only their writes.

`views/js/checkout-mutation-client.js` adds client-side latest-intent-wins protection without replacing server validation:

- newer mutation increments a monotonic sequence;
- prior request is aborted through `AbortController` where available;
- every response is discarded when its sequence is no longer latest;
- `stale_state` may advance to the server-provided current version and replay the same latest intent exactly once;
- other retryable errors are not automatically replayed;
- the complete returned section set is validated before any DOM replacement.

Identity, saved-address, native address editor, carrier, payment and agreement requests share the same sequence/abort/state-version channel. A slower old response cannot overwrite a newer checkout intent merely because cancellation races.

Virtual carts omit `delivery` from context-aware required refreshes because no delivery section exists in their shell DOM. A future cart mutation that can change physical/virtual topology requires an explicit insert/remove contract before exposure.

### Server-side payment/agreement selection persistence

Validated payment/agreement selections are persisted in `jzopc_checkout_selection`; browser values never become `CheckoutServerSelections` directly. `CheckoutMutationOrchestrator` loads current selections only after acquiring the cart mutex, uses them for stale-state validation, and saves new selections only after a successful mutation returns every dependency-required section.

The store is scoped by the loaded cart's `(id_shop, id_cart)` and records `id_customer` as an additional binding. A customer mismatch deletes the stale row and returns empty selections. It never loads a cart by submitted browser ID.

Only canonical payment option state, normalized agreement identifiers and update timestamp are stored. No monetary values, payment credentials, payment form data, CSRF/session/auth tokens or customer/address PII are persisted. Failed, stale, CSRF-rejected and incomplete mutations do not overwrite the row.

Identity, successful address persistence and real carrier transitions invalidate prior payment/agreement selections before fresh rendering.

### Authoritative selection rendering

Payment/agreement section refreshes receive `CheckoutServerSelections` only from the server-side mutation flow. Payment radios are checked only when the fresh Core-presented module/option matches canonical persisted `module:option`. Agreement checkboxes are checked only for canonical persisted agreement keys. Browser-submitted checked state is never copied directly into returned HTML.

The browser client accepts server section HTML only after verifying that each response key maps to an existing section and the fragment contains exactly one matching `data-jzopc-section` root. This is a consistency guard, not an HTML sanitizer.

### Monetary tampering

`PrestaShopCheckoutStateFactory` has no browser monetary inputs. Cart/totals fingerprints come from Core cart/address checksums and `Cart::getOrderTotal()` calculations. Identity/address/carrier/payment/agreement mutations accept no browser monetary authority.

### Payment tampering

`CheckoutPaymentSelectionParser` accepts only bounded payment option/module identifiers. `CheckoutPaymentSelectionService` regenerates current Core-backed payment options and requires exact module key, option ID and presented module-name agreement before returning a canonical server selection.

`CheckoutPaymentSelectionMutation` performs parsing and fresh Core validation inside the cart-mutex/stale-state critical section. A successful payment change revalidates previously approved agreements; obsolete approval is cleared.

A validated payment selection is **not** final-submit authorization. Final submission must regenerate eligibility and follow the payment module's native form/redirect/binary flow. The module must not call `PaymentModule::validateOrder()` as a shortcut around third-party payment-module contracts.

### Legal-agreement tampering

`PrestaShopCheckoutAgreementsPresenter` discovers required conditions through Core `ConditionsToApproveFinder`, preserving shop terms and `termsAndConditions` module contributions. `CheckoutAgreementSelectionParser` accepts only bounded safe identifiers. `CheckoutAgreementSelectionService` regenerates the fresh Core set and succeeds only when submitted keys exactly equal all currently required identifiers.

Agreement validation must run again during final submission immediately before payment/order handoff.

### Rendering / XSS boundaries

Module-owned address, delivery, payment, agreement, identity-summary and cart strings are escaped according to HTML context. Raw HTML is intentionally limited to PrestaShop-defined server-rendered Core/theme/module markup:

- active theme native `CustomerForm` and `CustomerLoginForm` output, including module-added customer fields;
- active theme native `CustomerAddressForm` output, including module-added address fields;
- carrier `displayCarrierExtraContent`, `displayBeforeCarrier`, `displayAfterCarrier`;
- payment `displayPaymentTop`, `PaymentOption::additionalInformation` and module forms;
- legal-condition HTML returned by `ConditionsToApproveFinder::getConditionsToApproveForTemplate()`;
- section HTML already produced by trusted server renderers when composed into the module shell.

Request values are passed through Core forms/validation. None of these raw boundaries may be widened to arbitrary browser-controlled or customer-stored HTML.

### SQL / injection

Direct SQL is limited to narrow infrastructure boundaries:

- advisory-lock acquisition/release through Doctrine DBAL with positional parameters (`GET_LOCK(?, ?)` / `RELEASE_LOCK(?)`);
- checkout-selection runtime reads/writes/deletes through Doctrine DBAL with all row values parameterized;
- install/upgrade/uninstall DDL generated only from the PrestaShop database prefix and engine after strict identifier validation.

Future direct SQL must remain parameterized where values are involved and justified by correctness/performance.

## Threat status

| Threat | Current status | Release requirement |
| --- | --- | --- |
| CSRF | Shared guard implemented; identity can rotate Core front token only after guarded completion; address form has separate server-generated Core persister token | Final endpoint must use equivalent or stronger CSRF/state boundary; execute identity rotation browser/runtime tests |
| Cross-cart/cart takeover | Cart binding implemented and used by all concrete mutations | Never load submitted cart IDs in handlers |
| Auth-driven cart replacement | Core `PS_CART_FOLLOWING` transition detected; old-cart AJAX continuation blocked and full order-page reload required | Execute real login/cart-restoration runtime/browser cases |
| Customer mismatch | Generic guard + selection-store binding + post-identity cart/customer equality check | Keep resource ownership checks on every future mutable resource |
| Guest/account identity | Core customer form/persister/login path implemented behind readiness gate | Execute deferred guest/account/login/validation/module-field runtime/browser matrix before readiness |
| Password handling | Core `CustomerPersister`/hashing owns password processing; module does not hash/store/log password | Preserve Core ownership and test representative password-policy failures |
| Address IDOR | Saved-address selection and address edit use `customerHasAddress`; native persistence uses Core persister | Browser/runtime matrix must prove foreign edit rejection and identity-to-address transition |
| Address validation/persistence | Core form + formatter + persister implemented; save applies through `CheckoutSession` | Execute deferred installed/browser add/edit/country/state/validation contracts |
| Address/cart delivery consistency | Saved/new delivery addresses apply through Core checkout session | Execute real runtime address contracts |
| Forged carrier | Fresh Core-option validator, guarded endpoint and stale-safe browser transport implemented | Final submit must revalidate carrier; representative/no-carrier matrix must pass |
| Forged payment option | Fresh Core-backed validator, guarded endpoint and server persistence implemented | Final submit must revalidate fresh eligibility immediately before handoff |
| Forged/missing agreement | Fresh exact-set validator, guarded endpoint and persistence implemented | Final submit must revalidate fresh agreement set immediately before handoff |
| Monetary tampering | Server-only totals/fingerprint calculation; mutation endpoints accept no money authority | Final submit must compare/recalculate fresh payable state |
| Stale browser state | Server state-version guard + AbortController/sequence latest-wins + bounded stale retry implemented | Prove rapid live browser changes and module reinitialization |
| Concurrent same-state writes | Per-cart mutex covers selection load/guard/write plus identity/address/carrier mutation | Final order path needs an idempotent/stronger order boundary |
| Partial/malformed AJAX section apply | Complete response section set prevalidated before DOM writes | Exercise malformed/partial/out-of-order browser responses |
| Virtual-cart delivery refresh | Context-aware dependencies omit nonexistent delivery section; carrier mutation rejects virtual carts | Future topology-changing cart mutations need explicit insertion/removal semantics |
| Version-specific checkout takeover | 9.0/9.1 adapter and isolated 9.2+ provider implemented behind readiness gate | Execute deferred runtime + browser gates before opening gate |
| Endpoint exposure before takeover | Shared activation gate remains closed; assets are OrderController-only and gated | Keep readiness false until integration gates pass |
| XSS | Normal values escaped; raw Core/theme/module HTML isolated, including native identity/address forms | Do not widen trusted boundaries to arbitrary browser/customer input |
| SQL/injection | Runtime DML parameterized; DDL identifiers validated | Parameterize and justify future direct SQL |
| Duplicate order submission | **Not implemented** | Final-submit idempotency/order guard is a release blocker |
| Payment/order tampering | Selection validation exists; final handoff absent | Revalidate complete fresh checkout and preserve native payment-module flow |
| Persisted stale selection rows | Customer mismatch plus identity/address/carrier invalidation implemented | Successful-order deletion and bounded abandoned-cart cleanup required |

## Logging rules

Server logs may include operation name, shop ID, cart ID and non-sensitive machine error codes. Do not log passwords, payment credentials/secrets, CSRF/auth tokens, cookie/session identifiers, full customer payloads or unnecessary address/PII fields.

The browser client emits lifecycle events but does not log tokens, endpoint bootstrap data, customer/address form payloads or payment form data. Identity lifecycle detail contains only the operation kind (`create` or `login`), not form values.

## Verification status

Tests and runtime contracts continue to be created normally. The latest identity/address/carrier source, JavaScript, smoke and installed-runtime checks have **not** been executed because the repository's GitHub Actions free quota remains exhausted and the current execution environment does not provide a local repository/runtime. They must run after quota reset; no unexecuted check is considered passing.

The identity contract now requires Core customer/login form classes, `PS_GUEST_CHECKOUT_ENABLED`, `actionSubmitAccountBefore`, no module-owned password/direct customer persistence, guarded identity endpoint wiring, full dependency refresh, trusted bootstrap URL, delegated form serialization, completed-response CSRF rotation, no rejected-response token disclosure, anonymous installed Smarty form rendering and the cart-restoration reload boundary.

Previously completed installed-runtime checks before quota exhaustion covered PrestaShop 9.1.5 and 9.2.0-beta.1 module installation, capability/hook behavior, native OPC conflict detection and Core process/session adapter construction. Those older results do not validate the latest identity/address/carrier delta.

## Release-blocking security work

The module is intentionally not production-ready until:

1. deferred PHP/Node/smoke/installed-runtime tests are executed and all failures fixed;
2. a controlled live browser matrix proves guest/account/login, CSRF rotation, Core cart restoration, native address interaction, no-carrier behavior, stale/race handling and representative payment/carrier modules;
3. final checkout validation rechecks customer/cart binding, addresses, carrier, payment, agreements and Core-calculated payable totals;
4. duplicate/replayed final submission cannot create two orders;
5. selected payment handoff preserves each payment module's native form/redirect/binary contract rather than duplicating order creation;
6. successful-order and abandoned selection rows are cleaned up safely;
7. only then may the production readiness gate be reconsidered.

See ADR-0007, ADR-0008, ADR-0009, ADR-0013, ADR-0014, ADR-0015 and ADR-0016 for browser transport, trusted bootstrap, version-specific integration, saved-address, carrier, native address-form and Core identity decisions.

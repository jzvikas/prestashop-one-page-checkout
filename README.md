# PrestaShop One Page Checkout

Production-grade One Page Checkout module under active development for PrestaShop 9.x and PHP 8.4+.

> Current status: the module now has a trusted server-generated checkout shell/bootstrap plus guarded version-specific checkout process adapters for both PrestaShop 9.0/9.1 and 9.2+. CI installs the module into real PrestaShop 9.1.5 and 9.2.0-beta.1 shops backed by MariaDB and verifies the version-specific capability/hook boundary, including native `ps_onepagecheckout` conflict detection on 9.2. The activation gate intentionally remains closed until real shell rendering/browser tests prove checkout correctness. While that gate is closed, the module cannot take over checkout and mutation endpoints return `checkout_unavailable`.

## Runtime targets

- PrestaShop 9.x (`>=9.0 <10.0` while this compatibility matrix is under active verification)
- PHP 8.4+
- multistore and multilingual architecture required
- Classic/Hummingbird and third-party payment/carrier compatibility required

## Architecture baseline

The module detects and isolates the checkout integration path without blindly loading version-specific APIs:

- PrestaShop 9.0/9.1: `actionCheckoutRender` replaces only the Core checkout process while preserving its current `CheckoutSession`;
- PrestaShop 9.2+: `actionCheckoutBuildProcess` returns a real `CheckoutProcessProviderInterface` implementation from a 9.2-only autoload path;
- native `ps_onepagecheckout` conflict detection remains part of the shared activation policy;
- unsupported or ambiguous capabilities fail closed to native checkout;
- `INTEGRATION_SHELL_READY` remains `false` until runtime integration is proven.

`CheckoutProcessBuilder` creates a real Core `CheckoutProcess` around one module-owned `CheckoutShellStep`. The step extends Core `AbstractCheckoutStep` and renders through `renderTemplate()`, preserving the `actionCheckoutStepRenderTemplate` lifecycle. The module-owned shell uses the same server-authoritative cart/session/selections state as AJAX mutations rather than creating a second client-side checkout model.

The trusted browser bootstrap contains only current cart ID, Core front-office CSRF token, server state version and the payment/agreement mutation endpoint URLs. `CheckoutFrontendAssetRegistrar` registers the existing payment and stale-safe mutation controllers only on the order controller and only after the same activation gate passes. Existing installations receive the new media hook through the idempotent `0.3.0` upgrade script.

The application layer has a canonical server-state version token, stale-state guard and conservative section dependency graph. `PrestaShopCheckoutStateFactory` builds state from the loaded server-side cart, Core cart/address checksums and Core-calculated totals. Generic mutation safety covers CSRF, cross-cart/customer binding, per-cart serialization and stale-state ordering. The JSON transport layer provides stable status/error mapping.

Validated payment/agreement selections are persisted in the small module-owned `jzopc_checkout_selection` table, scoped by shop + cart and rebound to the current cart customer. The browser never supplies authoritative `CheckoutServerSelections`. `CheckoutMutationOrchestrator` loads them only after acquiring the cart mutex and saves new selections only after a successful handler returned all required refreshed sections.

A fail-closed checkout section renderer registry is in place:

- summary uses Core `CartPresenter`, preserving `actionPresentCart`;
- addresses are restricted to the cart-bound customer, rechecked with `Customer::customerHasAddress()`, and formatted with Core `AddressFormat::generateAddress()`;
- delivery uses the active Core checkout session, preserves `actionCarrierProcess`, `displayCarrierExtraContent`, `displayBeforeCarrier` and `displayAfterCarrier`, and skips shipping for virtual carts;
- payment uses Core `PaymentOptionsFinder::present()`, preserving payment-option discovery and `actionPresentPaymentOptions`, including actions, forms, inputs, additional information and binary markers;
- agreements use Core `ConditionsToApproveFinder::getConditionsToApproveForTemplate()`, preserving configured shop terms plus `termsAndConditions` hook contributions.

Payment and agreement renderers are state-aware during AJAX refresh: only the canonical server-persisted selection state can restore checked radios/checkboxes. Module-owned markup escapes ordinary values. Carrier/payment hook HTML and Core-formatted legal-condition HTML are explicit trusted Core/module HTML boundaries; browser data is never allowed to populate those raw HTML paths.

`views/js/payment-controller.js` is re-entrant after payment-section replacement, removes old handlers, synchronizes payment forms/additional information and publishes payment lifecycle events. It deliberately does not submit payment forms itself.

`views/js/checkout-mutation-client.js` activates only inside the trusted module checkout root. It sends the current CSRF/cart/state binding plus operation data, aborts superseded requests, ignores out-of-order responses, retries the latest intent at most once after `stale_state`, validates the complete returned section set before DOM replacement, advances the authoritative state version and emits `jzopc:section:updated` for re-initialization.

Payment selection is parsed strictly and accepted only when module + option ID match a fresh Core-backed payment-option presentation. Agreement selection is accepted only when its key set exactly matches every freshly discovered required Core/module condition. The concrete `paymentselection` and `agreements` module front controllers are POST-only and delegate state changes to `CheckoutMutationOrchestrator` inside the common activation/security boundary.

Remaining checkout sections are not exposed as fake placeholders. A mutation requiring an unimplemented renderer fails instead of returning an incomplete successful state.

`PrestaShopRuntimeProbe` deliberately allows PrestaShop to autoload legacy Core classes during capability checks. A real-install CI regression test caught the prior false-negative behavior caused by checking only already-loaded classes; the probe now distinguishes “autoloadable Core capability” from “class already touched in this process.”

See `docs/DISCOVERY.md`, `docs/ARCHITECTURE.md`, `docs/SECURITY.md` and ADRs under `docs/`, especially ADR-0008 and ADR-0009 for the shell/bootstrap and version-specific process decisions.

## Development setup

```bash
composer install
```

The raw source checkout expects the Composer autoloader. A release package will need the production dependencies/autoload artifacts required by an installed PrestaShop module.

## Local checks

```bash
composer validate --strict --no-check-publish
find . -type f -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
find views/js -type f -name '*.js' -print0 | xargs -0 -r -n1 node --check
for test in tests/Smoke/*Test.php; do php "$test"; done
```

Baseline CI executes the source checks on PHP 8.4 and Node.js 22. The separate `PrestaShop Runtime` workflow provisions MariaDB 11.4, installs real PrestaShop 9.1.5 and 9.2.0-beta.1, installs this module through the PrestaShop CLI, and executes the installed-runtime contract. The 9.2 job also installs a pinned native `ps_onepagecheckout` revision to prove conflict detection.

## Known limitations

- the 9.0/9.1 adapter and 9.2+ provider are implemented but intentionally unreachable while `INTEGRATION_SHELL_READY=false`;
- real installed-runtime CI currently covers PrestaShop 9.1.5 and 9.2.0-beta.1 capability/hook/install behavior, but not yet 9.0, live Smarty checkout rendering, HTTP/browser navigation or provider/reference-hook takeover with the readiness gate open;
- address, delivery, payment, agreements and summary have concrete renderers; identity/customer capture is not implemented yet;
- address add/edit forms are not rendered yet; the address section currently covers secure selection of saved addresses;
- no public address/customer/carrier mutation endpoint exists yet;
- the shared checkout-session provider still needs runtime proof on module front controllers before address/carrier mutation endpoints are exposed;
- payment/agreement mutation endpoints and the stale-safe browser client exist but remain unavailable while checkout takeover is disabled;
- selection rows are removed on uninstall, but successful-order/abandoned-cart cleanup still belongs to final-submit lifecycle work;
- no final-submit/idempotency/native payment handoff flow exists yet;
- Back Office checkout-flow activation UI is not implemented yet.

These limitations are intentional safety gates, not production-ready claims.

## Source of truth

Implementation requirements are defined in `ONE_PAGE_CHECKOUT_BUILD_PROMPT.md`. Repository Markdown instructions are treated as live requirements and must be reviewed before each implementation milestone.

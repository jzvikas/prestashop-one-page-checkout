# PrestaShop One Page Checkout

Production-grade One Page Checkout module under active development for PrestaShop 9.x and PHP 8.4+.

> Current status: safe integration shell plus server-authoritative checkout state, mutation security/concurrency/transport foundations, concrete address/delivery/payment/agreement/summary rendering, re-entrant payment interaction, fresh server validation for payment/agreement selections, cart-scoped server persistence, guarded payment/agreement mutation endpoints, and a stale-safe browser mutation client foundation. Checkout takeover remains deliberately fail-closed until the real version-specific checkout provider/legacy adapter supplies the one-page shell and trusted bootstrap; while that activation gate is closed, mutation endpoints return `checkout_unavailable` and cannot change checkout state.

## Runtime targets

- PrestaShop 9.x (`>=9.0 <10.0` while this compatibility matrix is under active verification)
- PHP 8.4+
- multistore and multilingual architecture required
- Classic/Hummingbird and third-party payment/carrier compatibility required

## Architecture baseline

The module detects and isolates the checkout integration path without blindly loading version-specific APIs:

- PrestaShop 9.0/9.1: `actionCheckoutRender` adapter path;
- PrestaShop 9.2+: `actionCheckoutBuildProcess` / `CheckoutProcessProviderInterface` path;
- native `ps_onepagecheckout` conflict detection;
- safe unsupported fallback when the expected capability is missing;
- fail-closed activation policy while the version-specific checkout process is incomplete.

The module installs only the checkout hook needed by the current PrestaShop family. The checkout-flow flag is disabled by default and forced off when the module is disabled. Both hook entry points currently preserve native checkout rather than exposing a partial custom flow.

The application layer has a canonical server-state version token, stale-state guard and conservative section dependency graph. `PrestaShopCheckoutStateFactory` builds state from the loaded server-side cart, Core cart/address checksums and Core-calculated totals. Generic mutation safety covers CSRF, cross-cart/customer binding, per-cart serialization and stale-state ordering. The JSON transport layer provides stable status/error mapping.

Validated payment/agreement selections are persisted in the small module-owned `jzopc_checkout_selection` table, scoped by shop + cart and rebound to the current cart customer. The browser never supplies authoritative `CheckoutServerSelections`. `CheckoutMutationOrchestrator` loads them only after acquiring the cart mutex and saves new selections only after a successful handler returned all required refreshed sections. Module version `0.2.0` includes the schema upgrade path for existing `0.1.0` installations.

A fail-closed checkout section renderer registry is in place:

- summary uses Core `CartPresenter`, preserving `actionPresentCart`;
- addresses are restricted to the cart-bound customer, rechecked with `Customer::customerHasAddress()`, and formatted with Core `AddressFormat::generateAddress()`;
- delivery uses the active Core checkout session, preserves `actionCarrierProcess`, `displayCarrierExtraContent`, `displayBeforeCarrier` and `displayAfterCarrier`, and skips shipping for virtual carts;
- payment uses Core `PaymentOptionsFinder::present()`, preserving payment-option discovery and `actionPresentPaymentOptions`, including actions, forms, inputs, additional information and binary markers;
- agreements use Core `ConditionsToApproveFinder::getConditionsToApproveForTemplate()`, preserving configured shop terms plus `termsAndConditions` hook contributions.

Payment and agreement renderers are state-aware during AJAX refresh: only the canonical server-persisted selection state can restore checked radios/checkboxes. Module-owned markup escapes ordinary values. Carrier/payment hook HTML and Core-formatted legal-condition HTML are explicit trusted Core/module HTML boundaries; browser data is never allowed to populate those raw HTML paths.

`views/js/payment-controller.js` is re-entrant after payment-section replacement, removes old handlers, synchronizes payment forms/additional information and publishes payment lifecycle events. It deliberately does not submit payment forms itself.

`views/js/checkout-mutation-client.js` is the dormant browser transport foundation for payment/agreement mutations. It activates only inside a module checkout root carrying a complete server-generated bootstrap (cart ID, CSRF token, state version and endpoint URLs), sends only the current server token/cart binding plus operation data, aborts superseded requests, ignores out-of-order responses, retries the latest intent at most once after a server `stale_state` response, validates the entire returned section set before changing DOM, advances the authoritative state version, and emits `jzopc:section:updated` so re-entrant section controllers can remount. Validation failures may still apply server-authoritative refreshed sections; malformed/incomplete responses fail closed.

Payment selection is parsed strictly and accepted only when module + option ID match a fresh Core-backed payment-option presentation. Agreement selection is accepted only when its key set exactly matches every freshly discovered required Core/module condition. The concrete `paymentselection` and `agreements` module front controllers are POST-only, reuse the common JSON/activation gate, retrieve only the public application mutation services, and delegate all authorization/state work to `CheckoutMutationOrchestrator`. Payment changes also clear previously approved agreements if the entire fresh agreement set is no longer exactly valid.

Remaining checkout sections are not exposed as fake placeholders. A mutation requiring an unimplemented renderer fails instead of returning an incomplete successful state.

See `docs/DISCOVERY.md`, `docs/ARCHITECTURE.md`, `docs/SECURITY.md` and ADRs under `docs/`.

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

CI executes the baseline on PHP 8.4 and Node.js 22.

## Known limitations

- no custom checkout process is returned yet on PrestaShop 9.2+;
- the 9.0/9.1 render hook does not mutate the native checkout process yet;
- address, delivery, payment, agreements and summary have concrete renderers; identity is not implemented yet;
- the shared checkout-session provider currently delegates to an active controller exposing Core `getCheckoutSession()`; a module-owned AJAX controller still needs a source-backed Core session construction path before carrier/address mutation endpoints can be exposed;
- address add/edit forms are not rendered yet; the address section currently covers secure selection of saved addresses;
- payment/agreement mutation endpoints and the stale-safe browser mutation client now exist, but the client deliberately remains dormant because no production checkout shell currently provides its trusted bootstrap/root or registers the assets as an active checkout flow;
- no public address/customer/carrier mutation endpoint exists yet;
- selection rows are removed on uninstall but successful-order/abandoned-cart lifecycle cleanup still belongs to the future final-submit lifecycle;
- no full PrestaShop runtime/Smarty/database-upgrade integration test is wired into CI yet;
- no final-submit/idempotency/native payment handoff flow exists yet;
- Back Office flow activation UI is not implemented yet.

These limitations are intentional safety gates, not production-ready claims.

## Source of truth

Implementation requirements are defined in `ONE_PAGE_CHECKOUT_BUILD_PROMPT.md`. Repository Markdown instructions are treated as live requirements and must be reviewed before each implementation milestone.

# PrestaShop One Page Checkout

Production-grade One Page Checkout module under active development for PrestaShop 9.x and PHP 8.4+.

> Current status: safe integration-shell + server-authoritative state/guard/concurrency foundation. Checkout takeover remains deliberately fail-closed until the real provider/legacy adapter is implemented and tested.

## Runtime targets

- PrestaShop 9.x (current verified strategy is bounded to `>=9.0 <10.0`)
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

The module installs only the checkout hook needed by the current PrestaShop family. The checkout-flow flag is disabled by default and is forced off on module disable. At this stage both hook entry points preserve native checkout rather than exposing a partial custom flow.

The application layer has a canonical server-state version token, stale-state guard and conservative section dependency graph. `PrestaShopCheckoutStateFactory` builds state from the loaded server-side cart, Core cart/address checksums and Core-calculated totals; browser monetary values are not part of this state path. `CheckoutMutationGuard` adds CSRF, cross-cart and cart/customer binding. `CheckoutCartMutex` serializes future mutations per cart through parameterized database advisory locks.

See `docs/DISCOVERY.md`, `docs/ARCHITECTURE.md`, `docs/SECURITY.md`, `docs/ADR-0001-checkout-integration-strategy.md`, `docs/ADR-0002-server-authoritative-checkout-state.md` and `docs/ADR-0003-prestashop-checkout-state-adapter.md`.

## Development setup

```bash
composer install
```

The raw source checkout expects the Composer autoloader. A release package will be required to include the production dependencies/autoload artifacts needed by an installed PrestaShop module.

## Local checks

```bash
composer validate --strict --no-check-publish
find . -type f -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
for test in tests/Smoke/*Test.php; do php "$test"; done
```

CI executes the same baseline on PHP 8.4.

## Known limitations

- no custom checkout process is returned yet on PrestaShop 9.2+;
- the 9.0/9.1 render hook does not mutate the native checkout process yet;
- no shared AJAX controller/response mapper exists yet;
- no customer/address/carrier/payment mutation API or final-submit flow exists yet;
- Back Office flow activation UI is not implemented yet.

These limitations are intentional safety gates, not production-ready claims.

## Source of truth

Implementation requirements are defined in `ONE_PAGE_CHECKOUT_BUILD_PROMPT.md`. Repository Markdown instructions are treated as live requirements and must be reviewed before each implementation milestone.

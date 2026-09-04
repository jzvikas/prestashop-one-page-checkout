# PrestaShop One Page Checkout

Production-grade One Page Checkout module under active development for PrestaShop 9.x and PHP 8.4+.

> Current status: foundation/discovery stage. The module does not take over checkout yet. This is intentional until version-specific integration, order-safety guards and compatibility tests are in place.

## Runtime targets

- PrestaShop 9.x
- PHP 8.4+
- multistore and multilingual architecture required
- Classic/Hummingbird and third-party payment/carrier compatibility required

## Architecture baseline

The first milestone adds a runtime capability layer that selects the supported checkout integration path without blindly loading version-specific APIs:

- PrestaShop 9.0/9.1: `actionCheckoutRender` adapter path;
- PrestaShop 9.2+: `actionCheckoutBuildProcess` / `CheckoutProcessProviderInterface` path;
- native `ps_onepagecheckout` conflict detection;
- safe unsupported fallback when the expected capability is missing.

See `docs/DISCOVERY.md` and `docs/ADR-0001-checkout-integration-strategy.md`.

## Local checks

```bash
find . -type f -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
php tests/Smoke/CheckoutCapabilityDetectorSmokeTest.php
```

CI also validates `composer.json` and generates the PSR-4 Composer autoloader on PHP 8.4.

## Source of truth

Implementation requirements are defined in `ONE_PAGE_CHECKOUT_BUILD_PROMPT.md`. Repository Markdown instructions are treated as live requirements and must be reviewed before each implementation milestone.

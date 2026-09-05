# ADR-0012: Installed Smarty checkout-shell runtime gate

## Status

Accepted.

## Context

The module already proves that its version-specific adapters can construct a real Core `CheckoutProcess` and preserve the exact Core `CheckoutSession` in installed PrestaShop 9.1.5 and 9.2.0-beta.1 shops. That contract stops before rendering.

Checkout takeover must not be enabled merely because the PHP process graph exists. The module shell depends on the real Smarty module template namespace, section presenters, the current Core checkout session, server-side selection persistence, CSRF generation, state-version construction and PrestaShop-generated module links. A source-only template assertion cannot prove those runtime boundaries work together.

## Decision

The installed-runtime matrix renders the actual module `CheckoutShellStep` through Core/Smarty while `INTEGRATION_SHELL_READY` remains `false`.

The contract:

- creates a real persisted runtime cart in the installed shop;
- initializes the real `OrderController` front container and obtains its Core `CheckoutSession`;
- resolves the module `CheckoutProcessBuilder` from the actual front-office module container;
- builds the real module Core `CheckoutProcess` and renders its `CheckoutShellStep`;
- requires the module step and checkout root markers to exist;
- requires address, payment, agreement and summary section roots to be present;
- requires the bootstrap cart ID to equal the persisted server cart;
- requires non-empty CSRF token, state version and payment/agreement mutation URLs;
- requires those URLs to target the module's concrete mutation controllers;
- cleans up the runtime cart after the contract.

The same contract runs on PrestaShop 9.1.5 and 9.2.0-beta.1 in the MariaDB-backed runtime matrix. It calls the process builder directly and does not modify configuration or bypass `JzOnePageCheckout::isCustomCheckoutActive()` in production code.

## Security and compatibility consequences

- A successful test proves that trusted browser bootstrap values can be generated from the installed server runtime rather than fabricated by a test double.
- CSRF, state version and endpoint values are asserted for presence/targeting but never printed by the contract.
- The test exercises the explicit Core/module raw-HTML trust boundaries through real Smarty rendering without adding a new browser-controlled raw HTML path.
- Native `ps_onepagecheckout` remains installed in the 9.2 conflict fixture; direct rendering is a test-only capability check and does not change the shared activation policy.
- An empty runtime cart is sufficient for this render boundary. Product/carrier/payment behavior with a realistic non-empty checkout remains part of the subsequent HTTP/browser integration matrix.

## Activation rule

This gate does not justify opening `INTEGRATION_SHELL_READY`. Production takeover remains disabled until HTTP/browser testing proves real order-page takeover/fallback, asset registration, mutation lifecycle behavior, and representative payment/carrier compatibility, and until the remaining identity/address/carrier/final-submit release blockers are implemented.

## Next milestone

After this installed Smarty contract is green, add a controlled HTTP/browser checkout harness that proves native fallback while disabled/conflicted and exercises the module root/assets/mutation lifecycle only through a safe testable activation boundary that cannot become a production bypass.

# ADR-0015: Core-native checkout address form editor

## Status

Accepted for the `build/core-address-persistence` implementation. Promotion to the integration-ready mainline remains blocked until the repository quality gates and installed PrestaShop runtime contracts can execute.

## Context

The checkout already supports ownership-safe selection of saved addresses, but production checkout also needs address creation and editing without duplicating PrestaShop country/state rules, required-field rules, address hooks, historization semantics, or module-added address fields.

PrestaShop 9.1 Core builds checkout addresses with `CustomerAddressForm`, `CustomerAddressFormatter` and `CustomerAddressPersister`. The formatter owns country/state and required-field discovery, the form owns Core validation and module validation hooks, and the persister owns customer authorization plus the used-address replacement semantics needed to avoid rewriting historical order addresses.

The native address form also carries its own Core persister token. That token is not the same authority as this module's guarded checkout mutation token.

## Decision

1. Address create/edit is implemented through Core `CustomerAddressForm`, `CustomerAddressFormatter` and `CustomerAddressPersister`; the module does not write `Address` or cart address headers directly.
2. Existing edit targets are pre-authorized with `Customer::customerHasAddress()` before Core form loading, avoiding IDOR and avoiding Core redirect behavior inside the JSON boundary.
3. The Core address-persister token is generated server-side with `Tools::getToken(true, $context)` and is overwritten into the native form before validation/persistence. A browser-supplied Core token is never treated as authority by the application service.
4. The rendered editor is the active theme's native `customer/_partials/address-form.tpl` output. This preserves active-theme field markup and module-added address fields instead of reimplementing Core form schemas in JavaScript.
5. Opening/editing an address and changing country are guarded POST refreshes. Country refresh calls `CustomerAddressForm::fillWith()` again so `CustomerAddressFormatter` regenerates country/state-dependent fields without persisting the address.
6. Address saves run through the existing CSRF/cart/state-version/cart-mutex mutation orchestrator. A successful save invalidates payment/agreement server selections and refreshes addresses plus all downstream checkout sections.
7. The browser serializer reserves `token`, `cartId`, and `stateVersion`. Native/theme/module form fields with those names cannot overwrite the trusted checkout bootstrap bindings. Core address authorization remains separately server-generated.
8. A saved delivery or invoice address is selected through Core `CheckoutSession::setIdAddressDelivery()` / `setIdAddressInvoice()` after persistence.
9. The checkout readiness gate remains closed. Guest/customer identity capture and final order submission/idempotency are still release blockers.

## Consequences

- Country/state, required fields, validation hooks, module-added fields, and used-address historization remain owned by PrestaShop Core.
- The module has two deliberately separate CSRF authorities: the outer OPC mutation token and the inner Core address-persister token. Only the outer token comes from the trusted checkout bootstrap; the inner token is regenerated on the server.
- Rendering depends on the active theme providing the standard PrestaShop address-form template contract. This must be exercised in the installed PrestaShop 9.1/9.2 runtime matrix and representative themes before the integration gate can open.
- Anonymous carts cannot save an address until a real cart-bound Core customer/guest identity exists; the service fails closed with `address_customer_required`.

## Required verification before promotion

- PHP syntax checks for the new/changed PHP sources.
- `CheckoutAddressFormContractSmokeTest.php`.
- `CheckoutAddressSectionRenderingSmokeTest.php`.
- `CheckoutBrowserBootstrapSmokeTest.php`.
- `CheckoutMutationJavascriptContractSmokeTest.php` plus `node --check views/js/checkout-mutation-client.js`.
- Full repository smoke suite.
- Installed PrestaShop 9.1 and 9.2 runtime contracts, including native Smarty address-form rendering.
- Browser coverage for add, edit, country/state refresh, validation failure, foreign-address rejection, stale-state retry, same-address mode, separate invoice mode, and payment/carrier invalidation after an address change.

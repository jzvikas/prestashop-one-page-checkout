# Checkout discovery notes

This document records the first source-backed checkout discovery pass required by `ONE_PAGE_CHECKOUT_BUILD_PROMPT.md`.

## Version split

### PrestaShop 9.0 / 9.1

Core builds the native `CheckoutProcess` in `OrderController` and then dispatches `actionCheckoutRender` with the checkout process passed by reference. The standard 9.2 provider contract is not available on these versions.

Initial integration decision: use a dedicated 9.0/9.1 adapter around `actionCheckoutRender`; do not reference 9.2-only interfaces and do not introduce a blanket controller override. The adapter must preserve Core `CheckoutSession`, persistence, validation, payment and carrier behavior.

### PrestaShop 9.2+

Core dispatches `actionCheckoutBuildProcess` before building the native multi-step process. Modules may return a `CheckoutProcessProviderInterface`; Core uses a custom process only when exactly one enabled valid provider exists. Otherwise Core falls back to the native checkout.

The native `ps_onepagecheckout` module is compatible with PrestaShop 9.2+ and uses this provider mechanism. Our module must keep its own provider disabled while the native OPC provider is enabled, instead of creating a two-provider conflict that would cause Core to fall back.

## Extension points that must be preserved

- checkout process lifecycle: `actionCheckoutRender` on 9.0/9.1; `actionCheckoutBuildProcess` on 9.2+;
- payment options: standard `paymentOptions` / `PaymentOptionsFinder`; preserve `PaymentOption` forms, actions, inputs, additional information and binary/self-submitting flows;
- payment presentation: `actionPresentPaymentOptions`;
- carrier lifecycle: `actionCarrierProcess`;
- carrier UI extension: `displayCarrierExtraContent`;
- checkout step/template integration where relevant: `actionCheckoutStepRenderTemplate`;
- order safety remains with PrestaShop `PaymentModule::validateOrder` and cart/order guards; the module must not duplicate payment order creation logic.

## AJAX and JavaScript implications

PrestaShop 9.2 native OPC refreshes checkout sections over AJAX and exposes lifecycle events through the PrestaShop event bus. Payment/carrier content can therefore be destroyed and rebuilt. Our client layer must be re-entrant and reinitialize third-party payment/carrier integrations after section refreshes.

Out-of-order requests must not overwrite newer checkout state. The implementation will use request cancellation and/or monotonic state versions.

## Theme implications

Classic/Hummingbird compatibility must be achieved through module-owned namespaced markup and preserved hook output, not by assuming one theme's Bootstrap or DOM details. Theme-specific behavior will be isolated behind rendering adapters if needed.

## Authoritative sources checked

- PrestaShop developer docs: One Page Checkout for module developers
  - https://devdocs.prestashop-project.org/9/modules/checkout/module-developers/
- Hook docs: `actionCheckoutBuildProcess`
  - https://devdocs.prestashop-project.org/9/modules/concepts/hooks/list-of-hooks/actioncheckoutbuildprocess/
- Hook docs: `actionCheckoutRender`
  - https://devdocs.prestashop-project.org/9/modules/concepts/hooks/list-of-hooks/actioncheckoutrender/
- Payment module API
  - https://devdocs.prestashop-project.org/9/modules/payment/
- Carrier extra content hook
  - https://devdocs.prestashop-project.org/9/modules/concepts/hooks/list-of-hooks/displaycarrierextracontent/
- Native OPC source
  - https://github.com/PrestaShop/ps_onepagecheckout
- PrestaShop Core checkout controller and provider resolver
  - https://github.com/PrestaShop/PrestaShop

Core source for each supported release remains authoritative when documentation and implementation differ.

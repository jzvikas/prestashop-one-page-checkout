# ADR-0013: Core-session-backed saved-address mutations

## Status

Accepted.

## Context

The checkout already renders only customer-owned saved addresses and has strict address-selection parsing, but the first service implementation changed `Cart::id_address_delivery` / `id_address_invoice` directly and called `Cart::save()`.

That is not equivalent to native checkout behavior. PrestaShop `CheckoutSession::setIdAddressDelivery()` calls `Cart::updateAddressId()` before saving the new delivery id, preserving per-product/customization delivery-address associations. Bypassing that path can leave cart delivery state internally inconsistent. Module front mutation controllers also do not expose `OrderController::getCheckoutSession()`, while delivery rendering and address mutation both require a Core-compatible session.

PrestaShop minor versions differ in session construction. PrestaShop 9.0 uses legacy `DeliveryOptionsFinder`. PrestaShop 9.1+ can use `PrestaShop\PrestaShop\Adapter\Shipment\DeliveryOptionsProvider` when `FEATURE_FLAG_IMPROVED_SHIPMENT` is available and enabled; that provider class does not exist on 9.0.

## Decision

1. `PrestaShopCheckoutSessionProvider` continues to reuse an active controller's `getCheckoutSession()` when available.
2. When running inside a module front controller, it constructs a Core `CheckoutSession` from the current server `Context`.
3. The PrestaShop 9.0-compatible path always remains available through `DeliveryOptionsFinder`.
4. The improved-shipment provider is referenced by class-name string and created dynamically only after both `class_exists()` and the feature-flag constant check succeed. This prevents a 9.1+ class from becoming a hard module-load dependency on 9.0.
5. `CheckoutAddressSelectionService` validates all submitted target addresses against the cart-bound customer before mutation and then uses Core `setIdAddressDelivery()` / `setIdAddressInvoice()` methods. It re-reads invoice state after a delivery mutation because Core may synchronize a previously linked invoice address automatically.
6. Address delivery/invoice/mode changes are represented by one `AddressSelectionUpdated` mutation so the browser sends one atomic intent instead of several racing requests.
7. The concrete `addressselection` module front controller is POST-only and delegates to `CheckoutMutationOrchestrator`, inheriting CSRF, loaded-cart/customer binding, per-cart mutex and stale-state checks.
8. A real address change clears the persisted payment/agreement selections before dependent sections are rendered because those validations may no longer be valid under the new address/tax/carrier context. An idempotent address request preserves them.
9. Physical address changes refresh addresses, delivery, payment, agreements and summary. Virtual carts omit delivery from the context-aware dependency set because no delivery section exists in the trusted shell DOM.
10. Browser address controls use the existing stale-safe mutation client and one trusted address endpoint URL. Missing invoice state is not translated in JavaScript; it is sent to the guarded server parser so the canonical translated validation error is returned.

## Security consequences

- submitted address IDs never load a different cart/customer and are authorized with `Customer::customerHasAddress()`;
- same-address mode does not trust a browser invoice id;
- address mutations cannot bypass CSRF, mutex or stale-state validation;
- old payment/agreement authority cannot survive a real address change;
- browser-calculated tax, shipping or payment eligibility is never accepted;
- normal values remain escaped by the existing section renderers.

## Compatibility consequences

- PrestaShop 9.0 does not load the 9.1+ `DeliveryOptionsProvider` class;
- PrestaShop 9.1/9.2 can follow Core's improved-shipment feature-flag branch;
- delivery-address side effects stay aligned with Core `CheckoutSession` rather than a module-specific cart mutation;
- virtual checkout keeps its intentional no-delivery-section DOM contract.

## Testing

Smoke contracts cover Core-session address setter semantics, linked delivery/invoice behavior, ownership rejection, virtual-section dependency filtering, guarded endpoint wiring, trusted bootstrap data and browser atomic address payloads.

The installed-runtime matrix also includes a module-front `CheckoutSession` contract for PrestaShop 9.1.5 and 9.2.0-beta.1. These new/updated tests are committed but have not been executed in this milestone because the repository's GitHub Actions free quota is exhausted. They must be run after quota reset before their runtime behavior is considered verified.

## Remaining work

This decision covers selecting existing saved addresses only. Address creation/editing, guest/customer identity, carrier selection, final checkout validation, duplicate-order/idempotency protection and native payment handoff remain release blockers. `INTEGRATION_SHELL_READY` remains `false`.

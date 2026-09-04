# ADR-0003: PrestaShop checkout state adapter

- Status: Accepted
- Date: 2026-09-05

## Context

The application-level `CheckoutState` contract needs a production adapter that derives state from PrestaShop rather than from browser-submitted values. Reimplementing Core cart identity logic would be fragile, while blindly hashing rendered HTML would make state tokens theme-dependent and expensive.

## Decision

`PrestaShopCheckoutStateFactory` builds checkout state from the current loaded `Cart`.

1. Cart/customer/shop/language/currency/address/carrier identifiers come from the server-side cart.
2. PrestaShop's native `CartChecksum` + `AddressChecksum` are used as the base cart identity fingerprint. The module augments that checksum with delivery option, carrier, gift/recyclable state, virtual-cart state and normalized cart-rule IDs because those values affect checkout dependencies but are not all covered by Core's checksum.
3. The totals fingerprint is generated only from `Cart::getOrderTotal()` results recalculated on the server. Browser price, tax, discount, shipping or total values are never accepted by the factory.
4. Payment selection and approved legal agreement keys enter through `CheckoutServerSelections`. Callers must populate this value object only after server-side validation/persistence of those selections; it is not a request DTO.
5. Zero legacy IDs are normalized to `null` where `CheckoutState` models an optional association.
6. Fingerprints are opaque SHA-256 values; their payload format is an internal implementation detail.

## Service-container boundary

Value objects and enums are not auto-registered as Symfony services. `config/services.yml` now registers only the focused stateless services/adapters that are meaningful container services. This avoids trying to autowire scalar-heavy checkout DTO constructors.

## Consequences

- state versions change when Core cart/address/product identity, carrier/delivery state, cart rules or Core-calculated totals change;
- state construction remains independent of theme markup and browser-calculated prices;
- Core cart checksum behavior is reused instead of copied;
- the next AJAX layer can compare a client state token against a fresh server snapshot before applying mutations;
- payment/agreement persistence remains a separate responsibility of the future checkout process/application layer.

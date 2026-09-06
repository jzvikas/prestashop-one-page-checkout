# ADR-0037 — Authoritative cart refresh after address commit

## Status

Accepted for the PrestaShop 9.1.5 checkout-correctness milestone. Production checkout readiness remains closed until the installed browser/runtime gate verifies the behavior.

## Context

Installed PrestaShop 9.1.5 runtime run `34024940439` established a sharper failure boundary for the fully orderable browser contract. The Chromium scenario persisted a real guest customer and Core delivery/invoice address but still rendered no `input[name="delivery_option"]` in the OPC delivery section.

The post-browser live-cart diagnostic then booted the PrestaShop front kernel and passed every server-authoritative delivery invariant against the exact browser-created cart: customer/group binding, address ownership, delivery country/zone, carrier zone availability, `Carrier::getCarriersForOrder()`, `Carrier::getAvailableCarrierList()`, the physical cart product, and `Cart::getDeliveryOptionList($country, true)`.

That executed evidence rules out the runtime carrier fixture and persisted Core cart/address eligibility as the cause. The remaining difference is same-request state: OPC renders dependent delivery/payment sections immediately after native `CustomerAddressForm`/`CheckoutSession` address persistence using the request's existing `Context::cart` object. That object may already have evaluated package/delivery calculations before the address mutation, whereas a freshly loaded Core cart sees the committed address state and produces a valid delivery option.

## Decision

1. Continue to persist address changes only through native `CustomerAddressForm`, `CustomerAddressPersister` and Core `CheckoutSession::setIdAddressDelivery()` / `setIdAddressInvoice()`.
2. After those Core writes succeed, reload the same cart ID into a fresh `Cart` object before any dependent OPC section is rendered.
3. Treat the reload as a server-authoritative binding boundary. The fresh cart must:
   - load successfully through Core;
   - retain the same positive customer ID;
   - retain the same shop ID;
   - contain the address binding just committed for the requested role;
   - contain the same address for invoice as well when `useSameAddress` was requested.
4. Fail closed with `address_cart_refresh_failed` if any of those invariants disagree. Do not render delivery/payment from an ambiguous or stale in-memory cart.
5. Replace only `Context::cart` with that freshly loaded Core object. Do not write a carrier ID, synthesize `delivery_option`, bypass Core eligibility, or create an order.
6. Keep payment/agreement server selections invalidated after a successful address-book mutation, as before.

## Security and correctness consequences

- Browser input still never becomes address or cart authority; customer/address ownership and the module checkout CSRF/cart/customer/stale-state guard remain unchanged.
- A cart/customer/shop rebinding between persistence and refresh is rejected instead of being silently rendered.
- Delivery options are still discovered by PrestaShop Core from the committed cart/address state.
- Third-party carrier hooks and native carrier selection remain owned by Core.
- Payment modules and Core remain the only order-creation owners; the OPC module does not call `validateOrder()` here.
- Reloading the cart removes same-request package/delivery cache ambiguity without altering database state beyond the preceding native Core address mutation.

## Verification

The failure-boundary evidence is executed: run `34024940439` passed the kernel-aware live-cart delivery diagnostic after the orderable Chromium failure. The authoritative-cart refresh implementation must not be treated as browser-verified until a subsequent exact-head 9.1.5 runtime run passes the orderable Chromium gate.

## Remaining work

After the orderable two-tab gate passes, continue to native payment completion and verify that the payment module creates the Core order, `actionValidateOrderAfter` clears the reservation, duplicate refresh remains idempotent, and failure/abandonment/TTL recovery is safe.

`INTEGRATION_SHELL_READY` stays `false`.
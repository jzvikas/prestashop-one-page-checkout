# ADR-0037 — Authoritative cart refresh after address commit

## Status

Rejected as a corrective hypothesis after installed PrestaShop 9.1.5 browser execution. The experiment has been reverted from production code. Production checkout readiness remains closed.

## Context

Installed PrestaShop 9.1.5 runtime run `34024940439` established a sharper failure boundary for the fully orderable browser contract. The Chromium scenario persisted a real guest customer and Core delivery/invoice address but still rendered no `input[name="delivery_option"]` in the OPC delivery section.

The post-browser live-cart diagnostic then booted the PrestaShop front kernel and passed every server-authoritative delivery invariant against the exact browser-created cart: customer/group binding, address ownership, delivery country/zone, carrier zone availability, `Carrier::getCarriersForOrder()`, `Carrier::getAvailableCarrierList()`, the physical cart product, and `Cart::getDeliveryOptionList($country, true)`.

A plausible hypothesis was that the address mutation request retained an in-memory `Context::cart` whose package/delivery caches had been populated before `CheckoutSession::setIdAddressDelivery()` / `setIdAddressInvoice()` committed the new bindings. An experiment therefore reloaded the same cart from the database after the native Core address write and checked cart/customer/shop/address bindings before replacing `Context::cart`.

## Experiment

The experiment deliberately preserved all Core ownership rules:

1. `CustomerAddressForm`, `CustomerAddressPersister` and Core `CheckoutSession` remained the only address-write path.
2. No carrier ID or `delivery_option` was written or synthesized.
3. No payment form was submitted and no order was created.
4. Reload failure or a changed cart/customer/shop/address binding failed closed.

Static CI for the experiment was executed successfully. Installed runtime run `34025335176` then exercised the exact PrestaShop 9.1.5 orderable Chromium scenario.

## Result

The hypothesis was disproved as a checkout fix. The fully orderable Chromium gate still failed because the OPC delivery section contained no Core `delivery_option`, while the post-browser live-cart diagnostic again passed and showed that a fresh Core delivery-option calculation for the persisted cart/address was valid.

Because the extra production cart reload did not change the observable browser outcome, it has been removed rather than retained as speculative complexity. Its dedicated smoke assertions were removed with it.

## Consequences

- Native Core address persistence remains unchanged.
- The repository does not carry an unproven `Context::cart` replacement path.
- The executed evidence continues to rule out persisted customer/address binding, delivery zone, fixture carrier eligibility and fresh Core `Cart::getDeliveryOptionList()` as the primary failure.
- The next diagnostic boundary is the address mutation response versus browser DOM: determine whether the server-generated `sections.delivery` already lacks the Core option or whether the option is lost during client-side section replacement.
- Carrier and payment Core mechanics must not be bypassed to make the browser gate pass.
- Payment modules and Core remain the only order-creation owners.

## Remaining work

Instrument the orderable browser contract with bounded, non-sensitive address-mutation response diagnostics. If the response delivery HTML lacks `delivery_option`, continue into same-request CheckoutSession/DeliveryOptionsFinder/presenter state. If the response contains it but the DOM does not, fix the section-update integration instead. Only after the real Chromium gate passes should work proceed to native payment completion, successful Core-order reservation cleanup, duplicate refresh and failure/abandonment/TTL recovery.

`INTEGRATION_SHELL_READY` stays `false`.
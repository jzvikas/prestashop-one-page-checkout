# ADR-0038: Refresh Core delivery-option cache at the presentation boundary

- Status: Accepted; installed 9.1.5 browser verification required
- Date: 2026-09-06

## Context

The fully orderable PrestaShop 9.1.5 Chromium contract reached native guest identity and Core address persistence but still rendered no `delivery_option` radio. ADR-0037 had already rejected replacing `Context::cart` with a freshly loaded `Cart`: the installed runtime continued to fail and the post-browser diagnostic proved the persisted cart/address/carrier state was valid.

A bounded browser diagnostic then classified the boundary directly. The successful `addresssave` response contained a `sections.delivery` fragment, but that fragment contained no Core `delivery_option`; the browser DOM therefore matched the authoritative mutation response rather than losing a carrier during section replacement. Immediately afterwards, the read-only live-cart diagnostic for the same browser-created cart found an eligible Core carrier and one delivery option when calling `Cart::getDeliveryOptionList(..., true)`.

PrestaShop 9.1.5 Core explains this otherwise contradictory result:

- `Cart::getDeliveryOptionList()` maintains a request-local static cache keyed by cart ID and returns the cached value unless `flush=true`;
- `DeliveryOptionsFinder::getDeliveryOptions()` calls `Cart::getDeliveryOptionList()` without requesting a flush;
- the OPC mutation guard/state calculation can execute shipping/totals logic before the native address persister commits the new delivery address, so the cart-ID cache can contain the pre-address no-carrier result for the remainder of that HTTP request;
- Core itself requests `getDeliveryOptionList(null, true)` inside `Cart::setDeliveryOption()` when it needs a fresh authoritative option set.

Object replacement cannot clear a function-static cache keyed by the same cart ID. That is why the ADR-0037 experiment was ineffective while a later explicit Core cache flush saw the correct carrier.

## Decision

For a physical cart, `PrestaShopCheckoutDeliveryOptionsPresenter` will refresh Core's own delivery-option cache with `Cart::getDeliveryOptionList(null, true)` after `actionCarrierProcess` and immediately before obtaining options through the existing Core `CheckoutSession` / `DeliveryOptionsFinder` presenter.

The module deliberately does **not** render from the raw refresh result. It only requires the result to be an array, then continues to use `CheckoutSession::getDeliveryOptions()` and `getSelectedDeliveryOption()` as the presentation authority. This preserves Core option normalization, selected-option semantics and third-party carrier hooks.

The module also does not call `setDeliveryOption()`, does not choose or persist a carrier, and does not accept a browser carrier as authority. Virtual carts still skip the delivery path entirely. An invalid Core refresh result fails closed.

## Ordering invariant

The required order is:

1. loaded physical Core cart;
2. `actionCarrierProcess` hook;
3. fresh Core `Cart::getDeliveryOptionList(null, true)` cache computation;
4. `CheckoutSession::getDeliveryOptions()` / selected-option presentation;
5. `displayBeforeCarrier` / `displayAfterCarrier` rendering hooks.

Refreshing before `actionCarrierProcess` would allow that hook to invalidate the just-computed eligibility. Rendering directly from the raw list would bypass Core checkout presentation behavior. Both are rejected.

## Security and correctness

This is a server-authoritative cache-coherency fix, not a compatibility shortcut. The browser contributes no eligibility data. Core recomputes shipping eligibility from the active cart, addresses, carrier restrictions and shop state; the module merely prevents an earlier same-request pre-address cache entry from being reused after Core has persisted the address.

Payment/order ownership is unchanged. The OPC module does not submit a payment or create an order as part of this refresh. Final preflight still independently revalidates carrier/payment/agreement/orderability before a finalization reservation can authorize native payment handoff.

## Verification

Source/smoke coverage locks the `actionCarrierProcess -> flush=true -> CheckoutSession` ordering, verifies virtual carts do not invoke the refresh, and rejects a presenter implementation that calls `setDeliveryOption()`.

The installed PrestaShop 9.1.5 orderable Chromium contract must execute successfully before this milestone is considered browser-verified. Until then this decision is an implemented fix backed by executed failure diagnostics, not a green compatibility claim.

`INTEGRATION_SHELL_READY` remains `false`.

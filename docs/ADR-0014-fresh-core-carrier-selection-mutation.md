# ADR-0014: Fresh-Core carrier selection mutation

## Status

Accepted, with the Core persistence contract hardened on 2026-09-05. The checkout readiness gate remains closed and the hardened regression checks are still pending execution while the repository Actions quota is exhausted.

## Context

Delivery options are rendered from the active Core `CheckoutSession`, but a browser-submitted delivery-option key is not proof that the option still exists or remains eligible. Address, cart, carrier-module or shipment state may have changed after rendering. Writing an arbitrary browser value through `Cart::setDeliveryOption()` would therefore create a forged/stale carrier path and could leave payment methods, totals or legal requirements inconsistent.

Core source also exposes two important persistence details that the original carrier milestone did not model precisely enough:

- `CheckoutSession::setDeliveryOption()` forwards the native `delivery_option` payload to `Cart::setDeliveryOption()`, and native checkout submits that payload as an address-keyed array (`delivery_address_id => delivery_option_key`), not as a bare option string;
- `DeliveryOptionsFinder::getSelectedDeliveryOption()` may return an automatically selected fallback even when the cart has not yet persisted an explicit `delivery_option`. It therefore cannot be used as the idempotency authority for a shopper selection.

The module already has a shared mutation boundary that provides POST-only transport, activation gating, front-office CSRF validation, loaded-cart/customer binding, a per-cart mutex and server state-version checks. `PrestaShopCheckoutSessionProvider` provides a version-safe Core `CheckoutSession` inside module front controllers.

## Decision

1. `CheckoutCarrierSelectionParser` accepts only bounded canonical Core option-key syntax: one or more positive carrier identifiers, each comma-terminated. Whitespace, zero/leading-zero identifiers and malformed comma forms fail closed. Browser carrier ids, prices, labels or totals are never authoritative inputs.
2. `CheckoutCarrierSelectionService` requires a loaded physical cart with a positive cart-bound customer and delivery address. The current delivery address is reauthorized with `Customer::customerHasAddress()` before carrier mutation.
3. The service resolves the current Core `CheckoutSession`, obtains its fresh `getDeliveryOptions()` set and accepts only an exact key present in that server-generated set.
4. Idempotency is decided from the cart's persisted `delivery_option` JSON for the current server-owned delivery address. Core's auto-selected `getSelectedDeliveryOption()` result is deliberately not accepted as proof that the shopper selection has already been saved.
5. A valid change is applied through `CheckoutSession::setDeliveryOption([$deliveryAddressId => $canonicalOption])`, matching the native `CheckoutDeliveryStep`/`Cart::setDeliveryOption()` payload shape while keeping the address identifier server-authoritative.
6. A successful Core write is re-read from `Cart::$delivery_option`; if the requested canonical option is not retained for the delivery address, the mutation fails closed as a system error.
7. The public `carrierselection` module front controller delegates to `CheckoutCarrierSelectionMutation` through the same guarded orchestrator used by address/payment/agreement mutations.
8. A real carrier change invalidates previously persisted payment and agreement authority. Carrier choice can change payable totals, payment eligibility and module-provided requirements, so `CarrierSelected` refreshes delivery, payment, agreements and summary from the new server state.
9. Invalid/stale/forged carrier keys return the current authoritative sections and a translated `carrier_selection_invalid` error; they do not mutate the cart or persisted selection authority.
10. Virtual carts reject carrier mutation because the trusted shell intentionally has no delivery section for them.
11. The trusted browser bootstrap includes only a server-generated carrier mutation URL. The stale-safe delegated mutation client sends only the selected opaque Core key and keeps the same AbortController/sequence/state-version protections as other checkout mutations.
12. `INTEGRATION_SHELL_READY` remains `false`; implementing and hardening this endpoint does not by itself enable checkout takeover.

## Security consequences

- forged delivery-option keys are checked against fresh Core availability inside the mutex/stale-state critical section;
- the current delivery address is reauthorized against the cart-bound customer before the option can be written;
- the browser never supplies the address key used for the native Core delivery-option payload;
- browser-supplied shipping price, carrier label and totals remain ignored;
- an auto-selected Core fallback cannot trick the mutation into treating an unsaved selection as already persisted;
- prior payment/agreement approval cannot silently survive a real carrier-state transition;
- a submitted cart id is still only a binding assertion and never loads another cart;
- CSRF, cross-cart/customer and stale-state protections remain centralized in the existing orchestrator/controller boundary.

## Compatibility consequences

- carrier persistence follows the address-keyed payload contract used by PrestaShop 9.0 and 9.1 `CheckoutSession::setDeliveryOption()` and native `CheckoutDeliveryStep`;
- PrestaShop 9.0 keeps the legacy `DeliveryOptionsFinder` session path while 9.1+ may use the improved-shipment provider through the existing guarded session adapter;
- third-party carrier hook content remains rendered by the existing delivery presenter; no individual carrier module is hard-coded into mutation logic;
- delivery section replacement stays compatible with the existing delegated browser listener, so replaced carrier DOM does not require per-fragment event rebinding.

## Testing

`CheckoutCarrierSelectionSmokeTest` now locks the hardened contract: canonical parsing, fresh option validation, first explicit persistence despite a Core auto-fallback, native address-keyed write shape, persisted-idempotency, delivery-address ownership, forged-option rejection and virtual-cart rejection.

These hardened checks have **not** been executed in this delta. GitHub Actions remain deferred because the repository's free Actions quota is exhausted. Earlier focused carrier checks were executed against the pre-hardening implementation and therefore are not evidence that this newer contract passes. The tests remain committed normally and must run after the quota resets.

## Remaining work

Representative carrier modules, no-carrier transitions and real HTTP/browser behavior still require the controlled runtime matrix. Identity/customer flow and final validation/idempotency/native payment handoff remain release blockers. `INTEGRATION_SHELL_READY` remains `false` until those activation requirements and deferred runtime gates are satisfied.

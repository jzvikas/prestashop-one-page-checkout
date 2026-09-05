# ADR-0014: Fresh-Core carrier selection mutation

## Status

Accepted.

## Context

Delivery options are rendered from the active Core `CheckoutSession`, but a browser-submitted delivery-option key is not proof that the option still exists or remains eligible. Address, cart, carrier-module or shipment state may have changed after rendering. Writing an arbitrary browser value through `Cart::setDeliveryOption()` would therefore create a forged/stale carrier path and could leave payment methods, totals or legal requirements inconsistent.

The module already has a shared mutation boundary that provides POST-only transport, activation gating, front-office CSRF validation, loaded-cart/customer binding, a per-cart mutex and server state-version checks. `PrestaShopCheckoutSessionProvider` also provides a version-safe Core `CheckoutSession` inside module front controllers.

## Decision

1. `CheckoutCarrierSelectionParser` accepts only a bounded Core delivery-option key syntax consisting of carrier identifiers and commas. Browser carrier ids, prices, labels or totals are never authoritative inputs.
2. `CheckoutCarrierSelectionService` requires a loaded physical cart, resolves the current Core `CheckoutSession`, obtains its fresh `getDeliveryOptions()` set and accepts only an exact key present in that server-generated set.
3. A valid change is applied through Core `CheckoutSession::setDeliveryOption()` rather than by editing cart fields directly. A failed Core persist is treated as a system failure, not a successful checkout mutation.
4. Re-selecting the already selected Core option is idempotent and does not rewrite cart state.
5. The public `carrierselection` module front controller delegates to `CheckoutCarrierSelectionMutation` through the same guarded orchestrator used by address/payment/agreement mutations.
6. A real carrier change invalidates previously persisted payment and agreement authority. Carrier choice can change payable totals, payment eligibility and module-provided requirements, so `CarrierSelected` refreshes delivery, payment, agreements and summary from the new server state.
7. Invalid/stale/forged carrier keys return the current authoritative sections and a translated `carrier_selection_invalid` error; they do not mutate the cart or persisted selection authority.
8. Virtual carts reject carrier mutation because the trusted shell intentionally has no delivery section for them.
9. `INTEGRATION_SHELL_READY` remains `false`; implementing this guarded endpoint does not by itself enable checkout takeover.

## Security consequences

- forged delivery-option keys are checked against fresh Core availability inside the mutex/stale-state critical section;
- browser-supplied shipping price, carrier label and totals remain ignored;
- prior payment/agreement approval cannot silently survive a real carrier-state transition;
- a submitted cart id is still only a binding assertion and never loads another cart;
- CSRF, cross-cart/customer and stale-state protections remain centralized in the existing orchestrator/controller boundary.

## Compatibility consequences

- carrier persistence follows the same `CheckoutSession::setDeliveryOption()` contract used by Core `CheckoutDeliveryStep`;
- PrestaShop 9.0 keeps the legacy `DeliveryOptionsFinder` session path while 9.1+ may use the improved-shipment provider through the existing guarded session adapter;
- third-party carrier hook content remains rendered by the existing delivery presenter; no individual carrier module is hard-coded into mutation logic.

## Testing

`CheckoutCarrierSelectionSmokeTest` covers bounded parsing, exact fresh-option validation, idempotency, Core-session persistence, forged-option rejection and virtual-cart rejection. The PHP 8.4 syntax gate and this focused smoke test were executed locally for the implementation. Repository-wide GitHub Actions and installed-runtime contracts remain deferred while the repository's Actions quota is exhausted and must not be treated as passing until they actually run.

## Remaining work

The carrier mutation must be connected to the trusted browser bootstrap/stale-safe mutation client before live checkout activation. Representative carrier modules, no-carrier transitions and real HTTP/browser behavior still require the controlled runtime matrix. Identity/address add-edit and final-submit/idempotency/payment handoff remain release blockers.

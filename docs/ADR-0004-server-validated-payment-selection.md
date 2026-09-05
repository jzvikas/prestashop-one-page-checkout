# ADR-0004: Server-validated payment selection

- Status: Accepted
- Date: 2026-09-05

## Context

Payment option identifiers and module names originate in browser markup and therefore cannot be treated as proof that a payment method is still eligible. Address, carrier, cart, currency or module state may change after the browser rendered its option list. The checkout already serializes mutations per cart and rejects stale state, so payment authorization must fit inside that same critical section rather than create a separate trust path.

## Decision

Introduce a dedicated payment-selection boundary under `src/Checkout/Payment`.

1. `CheckoutPaymentSelectionParser` accepts only the small `paymentOptionId` + `paymentModule` request contract and rejects missing, malformed or oversized identifiers.
2. `CheckoutPaymentSelectionService` does not trust that parsed pair. It asks the existing Core-backed `CheckoutPaymentOptionsPresenterInterface` for a fresh payment option set and requires an exact module-key, option-ID and presented `module_name` match before accepting the selection.
3. Accepted selections are converted to a canonical `module:option` state key before entering `CheckoutServerSelections`. Existing approved legal-agreement keys are preserved when payment selection changes.
4. The validator uses the same presenter path that delegates to Core `PaymentOptionsFinder::present()`, preserving PrestaShop payment option discovery and `actionPresentPaymentOptions` rather than maintaining a second payment registry.
5. No public payment mutation endpoint is exposed by this milestone. When that endpoint is added, parsing and validation must execute inside the existing `CheckoutMutationOrchestrator` cart-mutex/stale-state critical section before the validated key is persisted or returned as authoritative state.
6. Final payment submission remains a separate boundary. A validated selection is eligibility evidence for the current checkout snapshot, not permission to call `PaymentModule::validateOrder` directly or bypass a payment module's native form/redirect/binary handoff.

## Security consequences

- forged payment module names are rejected;
- forged or stale payment option IDs are rejected against a fresh Core-generated option set;
- a browser cannot populate `CheckoutServerSelections` directly;
- payment eligibility still must be revalidated during final submission because checkout state may change after selection;
- no payment secrets, credentials, monetary values or module form payloads are persisted by this selection boundary.

## Performance consequence

The selection validator currently reuses the Core-backed presentation adapter. A future concrete mutation handler should avoid immediately rediscovering the same payment option set again in the same request when it can safely pass/reuse the validated presentation result for rendering. Correctness and hook compatibility take precedence over premature caching.

## Testing

`CheckoutPaymentSelectionSmokeTest` covers strict parsing, forged option/module rejection, presented module mismatch rejection and canonical server-selection merging while preserving existing agreement keys. Full PrestaShop runtime payment-module integration remains a separate CI gap.

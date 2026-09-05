# ADR-0005: Core-backed checkout agreements and server validation

- Status: Accepted
- Date: 2026-09-05

## Context

PrestaShop builds checkout legal conditions through `ConditionsToApproveFinder`. That finder combines the configured shop terms with `termsAndConditions` hook output, reduces duplicate identifiers using Core ordering rules, and formats each condition for checkout markup. Browser checkboxes cannot be trusted as evidence that the currently required set is still complete because modules or shop configuration may change between render and mutation/final submission.

## Decision

1. `PrestaShopCheckoutAgreementsPresenter` delegates discovery to Core `ConditionsToApproveFinder::getConditionsToApproveForTemplate()` rather than duplicating terms/module logic.
2. `AgreementsSectionRenderer` renders module-owned accessible checkbox markup while treating only Core-formatted condition HTML as an explicit trusted Core/module HTML boundary.
3. `CheckoutAgreementSelectionParser` accepts only a bounded list of safe agreement identifiers and normalizes duplicates/order.
4. `CheckoutAgreementSelectionService` regenerates the current Core condition set and accepts approval only when the submitted key set exactly matches every currently required identifier. Missing and forged identifiers fail closed.
5. Validated keys are merged into `CheckoutServerSelections` while preserving the already server-validated payment selection.
6. No public mutation or final-submit endpoint is exposed by this milestone. Agreement validation must execute again inside the cart mutex/stale-state critical section when those endpoints are added; final submission must revalidate the fresh condition set immediately before payment/order handoff.

## Security consequences

- browser-provided labels/HTML are never accepted;
- hidden/forged agreement identifiers cannot enter authoritative state;
- omitting one required Core/module condition fails closed;
- raw agreement HTML is limited to the same Core/module output boundary used by native checkout.

## Testing

Smoke coverage verifies strict parsing, exact-set validation, payment-selection preservation, Core presenter delegation and renderer/template wiring. Full PrestaShop/Smarty runtime validation remains a CI gap until an integration installation is added.

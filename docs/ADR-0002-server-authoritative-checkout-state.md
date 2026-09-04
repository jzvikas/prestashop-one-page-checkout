# ADR-0002: Server-authoritative checkout state and refresh dependencies

- Status: Accepted
- Date: 2026-09-04

## Context

One-page checkout mutations are coupled. Address changes can alter taxes, carriers, payments and totals; cart changes can turn a physical cart into a virtual cart; rapid browser requests can complete out of order. Updating only the visually edited block can therefore leave the browser presenting a stale or internally inconsistent checkout.

## Decision

Introduce a small application-level state contract before implementing mutation endpoints.

1. `CheckoutState` is an immutable server-built snapshot of checkout identity and selection state plus server-generated cart/totals fingerprints.
2. `CheckoutStateVersioner` hashes a canonical payload into an opaque version token. Agreement key ordering is normalized so equivalent server state has the same token.
3. Every future state-changing AJAX operation must compare the browser's prior state token with the current server snapshot through `StaleCheckoutStateGuard` before applying a mutation when stale continuation could be unsafe.
4. `CheckoutSectionDependencyResolver` centrally defines which rendered sections become stale after each mutation. The map is deliberately conservative; correctness outranks avoiding a section render.
5. `CheckoutRefreshResult` defines the stable response shape for state version, refreshed HTML sections, machine-readable errors and optional redirect.
6. Prices, taxes, carrier cost, discounts and payable totals are never accepted from the browser as authoritative state. Their fingerprints must be derived from PrestaShop's recalculated server data.

## Consequences

- old AJAX responses can be rejected rather than overwrite newer browser state;
- all endpoints share one dependency graph instead of hand-maintained refresh guesses;
- future request cancellation in JavaScript is an additional optimization, not the only race-condition defense;
- server adapters still need to build the snapshot from the current cart/customer/session and provide meaningful cart/totals fingerprints;
- refresh dependencies can be widened without changing endpoint contracts.

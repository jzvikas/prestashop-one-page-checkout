# ADR-0006: Cart-scoped server persistence for validated checkout selections

- Status: Accepted
- Date: 2026-09-05

## Context

`CheckoutServerSelections` contains only server-validated payment and legal-agreement selections, and those values participate in the authoritative checkout state/version. They must therefore survive separate AJAX requests without becoming browser-authoritative.

PrestaShop 9.1 `CheckoutSession` persists cart/address/delivery state through Core, but it does not provide a persistence slot for the module's selected payment option or approved legal-condition key set. Reusing the general front-office cookie would also be a poor boundary: the PrestaShop cookie is shared with other storefront state and enforces a strict 4096-byte maximum, while the agreement contract intentionally permits a bounded set of multiple identifiers.

## Decision

1. Persist only the module's already validated payment/agreement selection state in the module-owned `jzopc_checkout_selection` table.
2. Key rows by `(id_shop, id_cart)` and store the current `id_customer` as an additional ownership binding. The store derives all three identifiers from the already loaded server-side cart; it never loads a cart from a browser-submitted ID.
3. A stored customer ID mismatch invalidates and deletes the stale row rather than transferring selection state to another cart owner.
4. Use Doctrine DBAL for runtime DML. All runtime values are parameters; the table identifier is composed only from a validated PrestaShop database prefix.
5. Keep the payload minimal: nullable canonical payment option, normalized agreement-key JSON and update timestamp. Do not store prices, totals, payment credentials, form payloads, tokens or customer/address PII.
6. `CheckoutMutationOrchestrator` owns store access. It loads selections only after acquiring the per-cart mutex, validates stale state using that server-loaded selection state, and saves new selections only after a successful handler returned every dependency-required section.
7. Failed, stale, CSRF-rejected or structurally incomplete mutations must not overwrite persisted selection state.
8. Installation creates the table; module version `0.2.0` includes an upgrade script so existing `0.1.0` installations receive the schema. Uninstall removes the table.
9. Final order handoff must delete the row once it no longer represents an active cart. Long-lived abandoned-row cleanup remains part of the final lifecycle work before production release.

## Consequences

- payment/agreement selections can participate in state-version checks across AJAX requests without trusting browser copies;
- same-cart mutation serialization now covers selection load, validation and persistence as one critical section;
- multistore state is explicitly scoped;
- the module owns one small database table and must maintain install/upgrade/uninstall compatibility;
- final checkout/order lifecycle work must clear successful/obsolete rows and add bounded cleanup for abandoned carts.

## Testing

Smoke coverage verifies schema shape, parameterized store behavior, customer-mismatch invalidation and orchestrator-owned load/save ordering. Full MySQL/MariaDB + live PrestaShop installation/upgrade integration remains a CI gap.

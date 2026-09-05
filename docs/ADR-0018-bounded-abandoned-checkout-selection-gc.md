# ADR-0018: Bounded abandoned checkout selection garbage collection

## Status

Accepted for implementation. This does not change `INTEGRATION_SHELL_READY=false` or provide runtime/browser readiness evidence.

## Context

`jzopc_checkout_selection` persists only server-validated payment-option and agreement selections keyed by shop/cart/customer. Successful order creation already deletes this transient row, and customer-binding mismatches delete stale ownership state. However, carts that are simply abandoned can otherwise leave rows indefinitely.

The table already has an indexed `date_upd` column. Keeping every abandoned row forever is unnecessary storage growth on large shops, while running a global cleanup query on every checkout mutation would add avoidable write load to the hot checkout path.

A stale row is not durable business data. Deleting it is safe: if that cart is opened again, Core checkout state is rebuilt and the shopper must select/approve the current payment and agreement state again before finalization.

## Decision

`DbalCheckoutServerSelectionsStore::save()` performs bounded opportunistic garbage collection before the current row is written:

- one save in 64 is selected with non-security `mt_rand()` sampling;
- only rows older than 30 days are eligible;
- each cleanup deletes at most 100 rows;
- the existing `date_upd` index supports the age predicate;
- the cleanup touches only the module-owned `jzopc_checkout_selection` table;
- current cart/order Core tables are never inspected or mutated by this GC;
- the current checkout save always proceeds normally after the optional purge.

The probability is a load-shedding mechanism, not a correctness/security decision. Selection validity remains governed by fresh Core state, cart/customer binding, stale-state checks and finalization preflight.

## Consequences

- abandoned transient selection storage becomes self-bounding under normal checkout traffic;
- large shops avoid a mandatory cleanup query on every selection mutation;
- low-traffic shops may retain old rows longer, which is acceptable because those rows carry no standalone authority;
- no schema migration is required because `date_upd` and its index already exist;
- successful-order lifecycle cleanup remains the immediate cleanup path;
- finalization reservations remain governed by their separate short TTL and order lifecycle cleanup.

## Verification

`CheckoutSelectionGarbageCollectionContractSmokeTest.php` records the retention, bounded-delete and hot-path sampling contract. Existing selection-store smoke coverage remains applicable because cleanup runs before the upsert and cannot change the just-saved row.

The new/affected smoke tests were **not executed** in this change because GitHub Actions quota is exhausted and this environment has no local repository/runtime. No unexecuted test is considered passing.

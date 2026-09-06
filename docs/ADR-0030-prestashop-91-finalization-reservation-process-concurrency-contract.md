# ADR-0030 — PrestaShop 9.1 finalization reservation process-concurrency contract

## Status

Accepted for the PrestaShop 9.1.5 production-readiness milestone. The contract is committed and wired into the runtime workflow, but it has not been executed while GitHub Actions quota is exhausted.

## Context

The finalization reservation store is the server-side duplicate-handoff barrier after final preflight and before native payment-module activation. Sequential MariaDB coverage already verifies acquisition, exact idempotent replay, competing-attempt rejection, customer-binding protection, exact release and TTL recovery.

Sequential calls do not prove the database behavior when two independent PHP requests both observe an apparently free cart and attempt to insert at nearly the same time. That race is important because real checkout tabs, retries, workers or parallel HTTP requests do not share PHP memory or one Doctrine connection.

## Decision

PrestaShop 9.1.5 gets an additional installed MariaDB runtime contract in `tests/Runtime/FinalizationReservationConcurrencyMariaDbContract.php`.

The parent process creates only synthetic high cart/customer identifiers for the module-owned `jzopc_checkout_finalization` table. It starts independent PHP worker processes. Each worker bootstraps the installed PrestaShop runtime, opens its own Doctrine DBAL connection and waits behind a common filesystem start gate before calling the production `DbalCheckoutFinalizationReservationStore::acquire()` method.

The contract verifies three process-level races:

1. two different attempts against the same cart/customer must produce exactly one acquisition and one fail-closed active-reservation rejection;
2. two exact replays of the same customer/state/payment/attempt may both resolve successfully, but the database must still contain only one reservation row;
3. two different customers racing against the same cart must still produce only one cart-level handoff barrier, so customer transition cannot create parallel payment authority.

The contract uses the production 900-second reservation TTL and the actual installed MariaDB schema. It does not create a Core order, invoke `PaymentModule::validateOrder()`, mutate Core checkout business data or open the production readiness gate.

The workflow step is deliberately scoped to the `9.1` matrix family. PrestaShop 9.2 support is not required to close the 9.1.5 production milestone.

## Consequences

This gate exercises the production duplicate-handoff storage algorithm through separate PHP processes and separate DB connections, including the unique-key/insert-conflict recovery path that cannot be proven by sequential calls alone.

It still does not prove the complete browser/payment lifecycle. A controlled two-tab browser test with a fully valid checkout remains required to prove that both tabs reach the HTTP finalization boundary correctly, only one receives native-payment handoff authority, stale/customer transitions cannot clear the winner, and successful Core order cleanup or bounded TTL recovery behaves correctly.

`INTEGRATION_SHELL_READY` therefore remains `false` until this and the remaining required runtime/browser/payment gates have actually executed successfully.

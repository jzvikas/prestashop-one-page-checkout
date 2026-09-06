# ADR-0029 — PrestaShop 9.1 finalization reservation MariaDB contract

## Status

Accepted. The contract is committed and wired into the PrestaShop 9.1.5 runtime job, but it has not executed while GitHub Actions quota remains exhausted.

## Context

Final-submit duplicate protection is intentionally server-authoritative. Source contracts already lock the reservation store's SQL and browser adapters, but source inspection alone cannot prove that the installed PrestaShop 9.1.5 + Doctrine DBAL + MariaDB runtime preserves the intended cart-level barrier semantics.

The production milestone for PrestaShop 9.1.5 therefore needs an installed-database gate before `INTEGRATION_SHELL_READY` can be reconsidered. The test must not create a fake order or bypass Core payment ownership merely to exercise cleanup behavior.

## Decision

Add `tests/Runtime/FinalizationReservationMariaDbContract.php` and execute it only in the configured PrestaShop 9.1.5 runtime matrix job.

The contract uses the installed module's real `DbalCheckoutFinalizationReservationStore` against the runtime MariaDB connection and a synthetic cart identity that exists only in the module-owned reservation table. It does not create a Core order, customer, address, payment transaction or other checkout business object.

The runtime gate verifies:

1. the installed finalization table exists;
2. the first reservation creates one active shop/cart barrier;
3. the exact same customer/state/payment/attempt replay is idempotent and does not duplicate the row;
4. a different attempt is rejected while the barrier is active;
5. changing the cart's customer binding does not remove or take ownership of the existing cart-level barrier;
6. a mismatched customer cannot release the original attempt;
7. a foreign attempt cannot release the reservation;
8. the exact original customer/attempt can release only while no Core order exists for the synthetic cart identity;
9. after forcing only the module-owned test row to expire, a new attempt can replace it and is active according to MariaDB server time;
10. test data is removed in `finally` even when an assertion fails.

The gate deliberately does not claim to prove true parallel browser/process contention. It proves the installed database/store semantics on which that later concurrent-tab browser test depends.

## Safety properties

- No `PaymentModule::validateOrder()` call is introduced.
- No Core order is created directly or indirectly by the contract.
- No checkout production flag is changed.
- `INTEGRATION_SHELL_READY` remains `false`.
- The test mutates only a synthetic row in `jzopc_checkout_finalization` and restores the prior `Context::cart` reference.
- MariaDB time remains authoritative for expiry.
- A passed runtime contract will not replace the still-required concurrent-tab/payment-module browser matrix.

## Verification state

The contract and workflow wiring are source-reviewed only in this change. GitHub Actions/runtime execution was intentionally not triggered because repository Actions quota is exhausted. It must not be described as passing until a real PrestaShop 9.1.5 runtime job executes it successfully.

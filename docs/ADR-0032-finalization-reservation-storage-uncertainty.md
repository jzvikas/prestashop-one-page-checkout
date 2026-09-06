# ADR-0032 — Fail-closed finalization reservation storage uncertainty

## Status

Accepted for the PrestaShop 9.1.5 production-hardening milestone. Runtime/browser execution remains deferred while GitHub Actions quota is unavailable.

## Context

The finalization reservation is the server-side barrier between a successful checkout preflight and the native payment-module handoff. A database write may fail after the server cannot prove whether the row was persisted, and a release may fail without proving that the row was removed. Treating either situation as an ordinary successful checkout/recovery result would weaken duplicate-handoff protection.

The persistence implementation already re-read an INSERT failure and preserved an active reservation when it could observe one. However, an inability to perform that follow-up read, an initial reservation-state read failure, or a release-statement failure escaped as an unclassified infrastructure exception. The generic front-controller handler still failed closed, but the application boundary could not distinguish reservation safety uncertainty from unrelated technical failures and could not return a stable checkout-domain error.

## Decision

Introduce `CheckoutFinalizationReservationUnavailable` as the explicit domain boundary for reservation persistence uncertainty.

`DbalCheckoutFinalizationReservationStore` now:

- wraps failures while purging/reading current reservation state before acquisition;
- after an INSERT exception, re-reads the shop/cart barrier and preserves exact-attempt idempotency or an observed competing reservation as before;
- if that post-write read itself fails, reports storage uncertainty rather than assuming the INSERT failed;
- reports a missing row after a failed INSERT as unavailable instead of authorizing a payment handoff;
- wraps `isActive()` database uncertainty so normal checkout mutations remain fail-closed;
- wraps exact-attempt release failures because a failed DELETE cannot prove that the duplicate-handoff barrier is gone.

`CheckoutFinalizationMutation` catches this domain error on `begin` and `release` and returns stable `finalization_unavailable` failure state. It never returns finalization success when reservation acquisition/release safety cannot be proven.

The existing `CheckoutFinalizationReservationAlreadyActive` contract remains separate: a positively observed competing reservation still maps to `finalization_in_progress`.

## Safety properties

- No OPC order is created by this change and `PaymentModule::validateOrder()` is not called.
- Native payment handoff is reached only after a positively successful reservation acquisition.
- Ambiguous INSERT outcomes cannot be interpreted as permission to hand off payment.
- Ambiguous release outcomes cannot be interpreted as proof that retry is safe.
- Existing shop/cart barrier semantics, exact customer/attempt release, atomic Core-order `NOT EXISTS` predicate and bounded TTL recovery remain unchanged.
- Raw database exception messages are not exposed to the shopper.

## Verification

`CheckoutFinalizationPreflightContractSmokeTest.php` is extended to lock the dedicated unavailable exception, ambiguous-write handling, release-failure handling and stable `finalization_unavailable` application error.

These checks are committed but not executed in this delta. The configured PrestaShop 9.1.5 MariaDB/runtime/browser gates must be executed once CI quota is available. The still-required fully orderable concurrent-tab native-payment handoff test is not replaced by this ADR.

## Consequences

A transient reservation-store problem may temporarily block checkout even when an INSERT or DELETE might actually have succeeded. This is intentional: bounded temporary blocking is safer than authorizing a second or unreserved native payment handoff under uncertain persistence state. Recovery continues through a later positively observed state, exact release where safe, successful Core-order cleanup, or reservation TTL expiry.

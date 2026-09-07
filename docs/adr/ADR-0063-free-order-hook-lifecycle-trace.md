# ADR-0063: Trace the free-order Core-to-OPC order lifecycle boundary in the disposable runtime fixture

## Status

Accepted for runtime diagnosis. Production checkout remains gated by `INTEGRATION_SHELL_READY=false`.

## Context

Exact-head PrestaShop 9.1.5 Native Payment Runtime `34109250449` on commit `b5c748c9c0446edbc89a5f18f92392db74d72575` executed the existing ambiguity, TTL-recovery and ordinary `ps_checkpayment` paths successfully, then failed only at the zero-total Core free-order completion gate.

The free-order browser request entered Core as the canonical `POST /order-confirmation?free_order=1` and did not exit before the bounded Chromium timeout. Failure-only evidence showed exactly one Core-created order for the cart while both OPC transient rows still existed. The ADR-0062 database-process diagnostic additionally reported no active database session, no database lock wait and no active transient-row delete at the observation point.

That evidence rejects the prior database-wait hypothesis. PrestaShop 9.1.5 `PaymentModule::validateOrder()` invokes `actionValidateOrderAfter` synchronously near the end of validation. The next useful distinction is therefore whether the free-order request reaches the OPC hook at all and, if it does, how far the hook progresses before the request stalls.

Changing production cleanup, creating an OPC-owned free order or synthesizing a confirmation redirect without that evidence would cross the Core ownership boundary and could regress the already-proven ordinary payment path.

## Decision

Instrument only the disposable `/tmp/jzopc-active-fixture*` copy of `jzonepagecheckout.php` with fixed structural `error_log()` markers around `hookActionValidateOrderAfter`.

The trace records only these lifecycle phases:

- hook entry;
- Core-order proof result;
- cleanup service resolution;
- cleanup begin;
- cleanup end;
- cleanup exception;
- hook exit.

The instrumenter is CLI-only, requires `JZOPC_RUNTIME_ACTIVE_FIXTURE=1`, accepts only an existing `/tmp/jzopc-active-fixture*` target, requires the copied readiness gate to be explicitly open, and hashes the repository production module before/after fixture modification to prove source isolation.

The active-fixture builder installs this trace after it opens and instruments the copied module. No runtime trace marker is added to production source.

## Security and compatibility consequences

- Trace messages are fixed structural strings. They contain no cart/customer/order IDs, query strings, request bodies, cookies, headers, CSRF tokens, secure keys, payment data or customer PII.
- The instrumenter cannot call `PaymentFree`, `validateOrder()` or any order/cart/transient-state write API.
- Core/payment modules remain the sole order-creation owners.
- Existing cart/customer binding, CSRF, stale-state protection, cart mutex and finalization reservation semantics are unchanged.
- The same fixture instrumentation applies to the already-green ordinary payment path, providing a useful control trace without changing that path.
- `INTEGRATION_SHELL_READY` remains `false` in repository production source.

## Verification

Source/smoke verification requires an exact-head CI result.

The zero-total milestone remains unverified until an exact-head PrestaShop 9.1.5 Native Payment Runtime executes the free-order path with these markers. The result will classify the blocker as either before OPC hook dispatch, during cleanup service resolution, during cleanup execution, or after cleanup. Only the observed boundary may justify a subsequent production fix.

Successful release verification still requires Core-owned exactly-one free-order creation, completed confirmation navigation and refresh stability, and removal of both OPC transient rows.

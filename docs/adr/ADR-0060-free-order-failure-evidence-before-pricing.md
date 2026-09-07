# ADR-0060: Free-order failure evidence precedes optional Core pricing

## Status

Accepted for the runtime test harness. Production checkout remains gated by `INTEGRATION_SHELL_READY=false`.

## Context

PrestaShop 9.1.5 Native Payment Runtime `34094176880` on commit `a1c049444222603c82e10d80fdaf8e4da2918ddf` reproduced the unresolved zero-total completion failure after all established ambiguity, TTL-recovery and ordinary `ps_checkpayment` gates had passed. Chromium completed OPC finalization and the runtime router recorded `phase=enter method=POST path=/order-confirmation`; no matching request exit appeared before the bounded browser timeout.

The failure-only CLI diagnostic then failed before printing order/reservation state. `Cart::getOrderTotal()` reached Core pricing with a CLI `Context` whose currency was not hydrated, causing Core computing-precision resolution to throw. This was diagnostic infrastructure failure, not evidence that production OPC pricing or order creation failed.

Repeated diagnostic bootstrap failures are unacceptable because they hide the single most important distinction for this blocker: whether Core already persisted an order before the request stalled, or whether the stall occurs before order creation.

## Decision

The free-order failure diagnostic now treats structural order/reservation state as primary evidence and Core total calculation as optional supplemental evidence.

It therefore:

1. reads only the fixture cart ID, Core order count/order ID, OPC reservation count and canonical payment selection;
2. emits those structural markers before invoking any Core pricing method;
3. hydrates the already loaded cart and its server-owned `Currency` into the CLI `Context` before `Cart::getOrderTotal()`;
4. reports whether total evidence is available and contains any total-calculation failure without printing exception details;
5. never converts a supplemental pricing failure into loss of the already emitted order/reservation evidence.

The Chromium free-order contract additionally requires the Core-presented action URL to bind `id_cart` to the exact trusted OPC cart before final submit. It does not invent or rewrite the Core action URL.

## Security and ownership boundaries

This change is test/runtime diagnostics only.

- The diagnostic remains CLI-only and `/tmp/prestashop` fixture-scoped.
- It remains read-only and does not insert, update or delete Core or OPC state.
- It does not read or print request bodies, cookies, CSRF material, secure keys, payment credentials, customer contact data or address payloads.
- It does not call `PaymentFree`, `PaymentModule::validateOrder()` or any order-creation path.
- The browser test validates Core-presented cart binding but does not modify the form action or synthesize order authority.
- Native payment/Core remain the sole owners of order creation.
- `INTEGRATION_SHELL_READY` stays `false`.

## Verification

The source/smoke/runtime changes introduced by this ADR are not considered verified until exact-head CI executes successfully. The zero-total milestone remains unverified until an exact-head Native Payment Runtime proves the full `free_order` handoff, Core order creation, confirmation identity, duplicate-refresh stability and OPC transient-state cleanup.

A failing runtime remains useful evidence only when its failure is reported as failure; it must never be described as green.

# ADR-0061: Core free-order action is context-cart authoritative

## Status

Accepted for the runtime/browser contract. Production checkout remains gated by `INTEGRATION_SHELL_READY=false`.

## Context

The exact-head PrestaShop 9.1.5 Native Payment Runtime on commit `c37a4d74c28a738c9a9b167e6dfeeb451a6945ff` failed before finalization because the Chromium contract required the Core-presented zero-total form action to contain `id_cart=<trusted cart>`.

That requirement does not match PrestaShop 9.1.5 Core. `PaymentOptionsFinder::findFree()` creates the synthetic `free_order` option with an action generated from `getPageLink('order-confirmation', ..., 'free_order=1')`; it does not add `id_cart`. `OrderConfirmationController::checkFreeOrder()` creates the free order from the already active `Context::cart`, validates the cart customer, delivery/invoice addresses and zero total, and only after Core creates the order redirects to the confirmation URL containing `id_cart`, `id_order`, `id_module=-1` and the cart secure key.

Requiring an initial `id_cart` query therefore caused the runtime test itself to reject the canonical Core request shape. Injecting `id_cart` into that action from OPC would be worse: it would mutate Core-presented payment mechanics and introduce browser-visible cart identity where Core intentionally relies on its session/context.

## Decision

The zero-total browser gate preserves the Core-presented action unchanged and treats the active Core session/context as cart authority.

The gate requires:

1. the selected presented option to be exactly `free_order`;
2. a same-origin POST action targeting Core order confirmation;
3. `free_order=1`;
4. no requirement that the initial action contain an `id_cart` query;
5. if an `actionPresentPaymentOptions` integration adds `id_cart`, it must equal the trusted OPC bootstrap cart or the gate fails closed;
6. normal OPC finalization preflight/reservation before native form handoff;
7. the final Core confirmation to contain the original trusted cart ID, a positive order ID and `id_module=-1`;
8. confirmation refresh stability and a server-side proof of exactly one Core-owned `free_order` order with OPC selection/reservation cleanup.

The browser therefore does not become authoritative merely because an optional `id_cart` query is present. Absence is the canonical PrestaShop 9.1.5 shape; a mismatching explicit value is rejected as an integration inconsistency.

## Security and compatibility consequences

- OPC does not append, rewrite or synthesize the free-order form action.
- OPC does not call `PaymentFree` or `PaymentModule::validateOrder()` and does not create the order.
- CSRF/cart/customer/stale-state guards, the same-cart mutex and DB-backed finalization reservation remain unchanged.
- The Core session/context remains authoritative for the cart used by `checkFreeOrder()`.
- `actionPresentPaymentOptions` output remains preserved; a compatible hook-added cart query is tolerated only when it agrees with the trusted cart.
- The post-order confirmation and server probe, not the initial browser query string, prove cart/order identity.
- `INTEGRATION_SHELL_READY` remains `false`.

This ADR supersedes only ADR-0060's statement that the initial Core-presented free-order action must itself carry `id_cart`. ADR-0060's diagnostic ordering and read-only evidence rules remain in force.

## Verification

The source/smoke correction is not considered verified until exact-head CI executes successfully. The zero-total milestone remains unverified until an exact-head Native Payment Runtime proves the full reserved `free_order` handoff, Core-owned order creation, confirmation identity, duplicate-refresh stability and OPC transient-state cleanup.

A queued or failing workflow must not be reported as green.
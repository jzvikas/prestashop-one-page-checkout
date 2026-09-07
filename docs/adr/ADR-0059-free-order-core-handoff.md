# ADR-0059: Preserve Core-owned zero-total order completion

## Status

Implemented with source/smoke coverage and a required PrestaShop 9.1.5 Chromium/runtime gate. The browser/runtime gate is still red: the latest executed runs prove the zero-total native form POST enters PrestaShop Core but does not complete within the bounded browser wait. The milestone remains unverified until the exact browser/runtime path succeeds.

## Context

PrestaShop 9.1.5 does not ask a merchant payment module to create a zero-total order. `PaymentOptionsFinder::findFree()` exposes a synthetic `free_order` payment option whose action targets Core `order-confirmation?free_order=1`. `OrderConfirmationController::checkFreeOrder()` performs duplicate detection and, only after rechecking the Core cart/customer/address/total state, creates the order through Core `PaymentFree::validateOrder()`.

The OPC payment presenter already delegated zero-total discovery to `PaymentOptionsFinder::present(true)`, but the final-submit path depended on a persisted merchant payment selection. A fresh zero-total checkout therefore had a correctness gap: Core could present its synthetic option while OPC finalization still rejected the checkout as having no selected payment method.

## Decision

Zero-total carts continue through the same OPC finalization safety boundary and the same Core-owned form action rather than introducing a module-owned order path.

1. The payment renderer preselects the synthetic option only when the server-derived cart total is free and Core presents exactly one unambiguous `free_order` option with the expected module identity.
2. At finalization begin, while already inside the existing cart mutex and CSRF/cart/customer/stale-state boundary, the mutation independently recomputes the cart total and asks a fresh `PaymentOptionsFinder()->present(true)` for the Core free-order option.
3. Only the exact single `free_order` shape is normalized into temporary server selections. Any missing, duplicated, malformed, hook-rewritten or exception-producing shape is left unresolved so the existing payment preflight fails closed.
4. The existing `CheckoutFinalizationPreflightService` then revalidates that normalized selection through `CheckoutPaymentSelectionService`, so a different option set between resolution and preflight is rejected.
5. The normal finalization reservation is acquired with the canonical `free_order:<option-id>` state key. The successful mutation persists that server selection together with the existing approved agreements.
6. Browser handoff remains the normal reserved native-form handoff. The synthetic form action points to Core order confirmation; the OPC module never calls `PaymentFree`, `PaymentModule::validateOrder()` or writes an order.
7. Successful Core order creation continues to trigger `actionValidateOrderAfter`, which removes OPC selection and reservation state.

## Security and correctness consequences

- Core remains the sole owner of free-order creation and duplicate protection.
- Zero-total status is derived from the loaded Core cart at finalization time, not from browser input.
- A cart changing from zero to positive total cannot retain free-order authority: fresh resolution is skipped and ordinary persisted-payment preflight applies.
- A hook that makes the synthetic free-order presentation ambiguous causes finalization to fail closed.
- The existing reservation still prevents concurrent OPC finalization attempts before Core receives the free-order request.
- No order, payment credential, CSRF token, address PII or customer payload is added to OPC persistence.
- Runtime fixture code is restricted to `/tmp/prestashop`, requires `JZOPC_RUNTIME_ACTIVE_FIXTURE=1`, and requires the installed module to resolve into `/tmp/jzopc-active-fixture*`.
- Failure-only diagnostics are read-only and expose only structural cart/order/reservation/selection markers. They do not read or print cookies, request bodies, CSRF tokens, secure keys, customer email or address PII.

## Verification

`CheckoutFreeOrderCoreHandoffContractSmokeTest.php` locks the Core finder use, exact single-option requirement, preflight reuse, reservation-backed handoff and prohibition on OPC-owned order creation.

The Native Payment Runtime contains a PrestaShop 9.1.5 zero-total gate. It creates a separate zero-price Core product only in the disposable runtime shop, then Chromium must complete guest identity, Core address/carrier state and agreements through normal OPC mutations. The browser requires one server-selected `free_order` option, a same-origin Core `order-confirmation?free_order=1` POST action, successful OPC finalization reservation, normal payment-handoff lifecycle, Core confirmation with `id_module=-1`, and stable cart/order identity after confirmation reload.

Executed Native Payment Runtime `34078730795` on commit `93da0e830dba9a17b1fd7ad251ab3e72d8d29228` passed the existing ambiguous-handoff, TTL-recovery and ordinary `ps_checkpayment` Core-order cleanup gates, then failed only in the new free-order Chromium step. The browser reached final submit and received the finalization HTTP response, but `page.waitForURL()` timed out after 30 seconds before any Core order-confirmation navigation was observed.

Executed Native Payment Runtime `34085518295` on commit `6be61c6f33dd5ba623d08261ea586e14eba5bd10` narrowed that failure boundary further. All existing ambiguity, same-cart TTL recovery and ordinary `ps_checkpayment` Core-order cleanup gates completed successfully. For the zero-total handoff the safe structural router recorded `phase=enter method=POST path=/order-confirmation content_length=0 content_type=form-urlencoded`, but no matching `phase=exit` was observed before Chromium's bounded 30-second navigation wait expired. This proves the browser reached the Core route and prevents misclassifying the blocker as an OPC native-form transport failure. It does not prove that Core created the free order.

Executed Native Payment Runtime `34089396385` on commit `70ca6de732e9340ca31d0a692d80e48f6bfec2ea` reproduced the same Core-entry/no-exit boundary after all existing ambiguity, TTL-recovery and ordinary `ps_checkpayment` completion gates passed. The new failure-only DB diagnostic then exposed a test-infrastructure defect before it could report order state: its latest-cart lookup appended `LIMIT 1` even though PrestaShop `Db::getValue()` appends that bound itself, producing invalid `LIMIT 1 LIMIT 1` SQL. The diagnostic query was corrected and a smoke regression forbids reintroducing that explicit limit.

Executed Native Payment Runtime `34089827690` on commit `5b308978d7b0dcb7bdbd4e312def0ea528494c73` again passed ambiguity reservation preservation, same-cart TTL recovery and ordinary `ps_checkpayment` Core-order cleanup before reproducing the zero-total Core-entry/no-exit timeout. The corrected latest-cart lookup ran, but the diagnostic exposed the same helper-only mistake in its selection lookup: an explicit `LIMIT 1` was passed to PrestaShop `Db::getRow()`, which also appends that bound, producing `LIMIT 1 LIMIT 1`. That explicit bound is now removed as well, and the smoke regression locks both `Db::getValue()` and `Db::getRow()` diagnostic queries against manual `LIMIT 1`. This executed run still does not establish whether Core had already persisted the free order.

Executed Native Payment Runtime `34090341078` on commit `82a2ca5aeeed75a94a8dc9ef80aaa7e3f216b559` again passed the established ambiguity reservation, same-cart TTL recovery and ordinary `ps_checkpayment` Core-order/cleanup gates, then reproduced the zero-total `POST /order-confirmation` entry without a matching exit before the bounded Chromium timeout. The failure-only diagnostic progressed past both previous `LIMIT 1` defects and exposed a third helper-only schema mismatch: it queried non-existent `payment_option_key` from `jzopc_checkout_selection`. The canonical persistence schema defines that column as `selected_payment_option`. The diagnostic now reads that exact production-schema column, and its smoke contract requires `selected_payment_option` while explicitly forbidding the stale `payment_option_key` name. Production persistence and order ownership were not changed by this correction. This run still does not establish whether Core had already persisted the free order because the diagnostic terminated before emitting its structural state markers.

The runtime workflow executes `ActiveCoreFreeOrderFailureDiagnostic.php` on failure after the free-order gate. The diagnostic is intentionally read-only and reports only whether the latest fixture cart remains zero-total/customer/address-bound, whether Core has already created an order, and whether the OPC reservation/canonical selection remain. This preserves the failing job while distinguishing a pre-order Core stall from a post-order hook/redirect stall on the next executed run.

A separate success-only completion probe still requires a loadable Core order with module `free_order`, zero paid totals, exactly one order for the cart, matching `Order::getIdByCartId()`, and zero remaining rows in both OPC finalization and selection tables. Neither fixture nor probe calls `PaymentFree`, `validateOrder()` or inserts an order.

Until the browser/runtime execution succeeds, zero-total completion remains a release blocker.

`INTEGRATION_SHELL_READY` remains `false` until this and the remaining representative payment/runtime gates are genuinely executed.

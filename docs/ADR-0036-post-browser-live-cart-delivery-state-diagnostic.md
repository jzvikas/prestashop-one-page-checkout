# ADR-0036 — Post-browser live-cart delivery-state diagnostic boundary

## Status

Accepted as executed diagnostic runtime coverage for the PrestaShop 9.1.5 production milestone. Production checkout readiness remains closed.

## Context

The installed-runtime run `34023693438` on commit `37303922abbd07a63c2c0d1790a92f3f5d844397` executed the active Core carrier availability contract successfully. The exact disposable carrier was retained by Core `Carrier::getCarriersForOrder()` for the configured guest group/default delivery zone and by `Carrier::getAvailableCarrierList()` for the physical runtime product.

The same 9.1.5 line of testing passed active checkout, finalization preflight and concurrent-tab preflight Chromium coverage but failed in the fully orderable two-tab contract because, after the real guest identity and Core address mutations, the rendered delivery section contained no `input[name="delivery_option"]`.

The first version of the post-browser diagnostic exposed two harness-only defects before it could answer the checkout question: an explicit `LIMIT 1` was incorrectly combined with PrestaShop `Db::getValue()` (which appends its own scalar limit), and the CLI process lacked the Symfony front kernel required by Core delivery calculation. Both diagnostic defects were corrected without changing browser expectations or production carrier behavior.

Installed-runtime run `34024940439` then executed the kernel-aware diagnostic after the orderable Chromium failure and passed every persisted Core invariant. The exact browser-created cart retained its customer/group binding, delivery and invoice address ownership, active country/zone, eligible Core carrier, physical product, and a non-empty `Cart::getDeliveryOptionList($country, true)` entry for the persisted delivery address.

That executed result removes fixture registration, persisted address binding and Core carrier eligibility as the primary explanation. The defect boundary therefore moves to same-request OPC checkout-session/delivery rendering after native address persistence.

Changing production carrier selection or injecting a synthetic browser delivery option would hide the failure and violate the server-authoritative checkout contract.

## Decision

1. Keep `tests/Runtime/ActiveCartDeliveryStateContract.php` as a PrestaShop 9.1.5 diagnostic/runtime contract.
2. Execute it after the orderable Chromium step with `if: always()` so the diagnostic still runs when that browser gate fails first.
3. Locate the live runtime cart through the unique physical fixture product in the fresh `--fixtures=0` runtime database and inspect only Core-persisted state.
4. Require the live cart to prove all of the following:
   - positive Core customer binding and loadable customer;
   - non-empty Core customer-group membership;
   - positive delivery and invoice address bindings;
   - both addresses remain owned by that customer;
   - the delivery address resolves to an active country and delivery zone;
   - the shop default carrier remains active and associated with the live address zone;
   - `Carrier::getCarriersForOrder()` retains it for the live customer's actual groups;
   - `Carrier::getAvailableCarrierList()` retains it for the actual product plus delivery address;
   - the Core cart still contains the runtime product;
   - a fresh `Cart::getDeliveryOptionList($country, true)` exposes at least one option for the persisted delivery address.
5. Boot the installed PrestaShop front kernel before the final delivery-option calculation so the diagnostic exercises the same Core Symfony services required by modern Cart delivery internals.
6. Let `Db::getValue()` own scalar limiting; do not append another `LIMIT 1` to its SQL.
7. Emit bounded identifiers/booleans and carrier/error codes on diagnostic failure. Do not emit customer names, email, street fields, tokens, cookies or payment data.
8. Keep the diagnostic read-only: it must not call `setDeliveryOption()`, write `delivery_option`, submit payment, call `validateOrder()` or create an `Order`.
9. Keep the existing Chromium assertion unchanged: the browser must still receive a real Core-rendered `delivery_option` before normal guarded carrier selection can continue.

## Security and correctness consequences

- The diagnostic cannot make an invalid checkout pass; it only identifies which server-authoritative invariant broke.
- Address ownership and actual customer groups are checked independently of DOM state.
- Core carrier APIs are evaluated again against the exact browser-created cart/address rather than the pre-browser probe cart.
- Delivery options are regenerated with Core cache flush, without persisting a selection.
- The OPC module still delegates carrier selection to Core and retains the existing CSRF/cart/customer/stale-state/cart-mutex boundary.
- Payment-module/Core order ownership is unchanged.

## Verification

Runtime run `34024940439` is executed evidence that the post-browser live Core cart is delivery-eligible even while the OPC orderable Chromium contract on that run fails to expose a delivery radio. This diagnostic gate is therefore passing evidence for the persisted-state side of the boundary, not for OPC delivery rendering or final-submit readiness.

## Remaining work

ADR-0037 applies the smallest same-request correction: after a successful native address commit, dependent OPC sections must render from a freshly loaded, binding-checked Core Cart rather than a potentially pre-mutation in-memory cart. That implementation still requires exact-head installed-browser verification. Native payment completion, successful Core-order reservation cleanup, failure/abandonment/TTL recovery and broader carrier/payment compatibility remain release blockers.

`INTEGRATION_SHELL_READY` stays `false`.
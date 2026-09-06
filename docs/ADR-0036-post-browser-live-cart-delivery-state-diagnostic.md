# ADR-0036 — Post-browser live-cart delivery-state diagnostic boundary

## Status

Accepted as diagnostic runtime coverage for the PrestaShop 9.1.5 production milestone. Production checkout readiness remains closed.

## Context

The installed-runtime run `34023693438` on commit `37303922abbd07a63c2c0d1790a92f3f5d844397` executed the new active Core carrier availability contract successfully. The exact disposable carrier was retained by Core `Carrier::getCarriersForOrder()` for the configured guest group/default delivery zone and by `Carrier::getAvailableCarrierList()` for the physical runtime product.

The same 9.1.5 job then passed active checkout, finalization preflight and concurrent-tab preflight Chromium coverage but failed again in the fully orderable two-tab contract because, after the real guest identity and Core address mutations, the rendered delivery section contained no `input[name="delivery_option"]`.

That evidence removes initial carrier registration/product eligibility as the primary explanation. The remaining boundary is the live browser-created cart: customer/group binding, delivery/invoice address persistence and ownership, delivery-address country/zone, or Core cart-level delivery-option assembly after the address mutation.

Changing production carrier selection or injecting a synthetic browser delivery option would hide the failure and violate the server-authoritative checkout contract.

## Decision

1. Add `tests/Runtime/ActiveCartDeliveryStateContract.php` for the 9.1.5 runtime milestone.
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
5. Emit bounded identifiers/booleans and carrier/error codes on diagnostic failure. Do not emit customer names, email, street fields, tokens, cookies or payment data.
6. Keep the diagnostic read-only: it must not call `setDeliveryOption()`, write `delivery_option`, submit payment, call `validateOrder()` or create an `Order`.
7. Keep the existing Chromium assertion unchanged: the browser must still receive a real Core-rendered `delivery_option` before normal guarded carrier selection can continue.

## Security and correctness consequences

- The diagnostic cannot make an invalid checkout pass; it only identifies which server-authoritative invariant broke.
- Address ownership and actual customer groups are checked independently of DOM state.
- Core carrier APIs are evaluated again against the exact browser-created cart/address rather than the pre-browser probe cart.
- Delivery options are regenerated with Core cache flush, without persisting a selection.
- The OPC module still delegates carrier selection to Core and retains the existing CSRF/cart/customer/stale-state/cart-mutex boundary.
- Payment-module/Core order ownership is unchanged.

## Verification

The pre-browser carrier gate is real passing evidence from runtime run `34023693438`. The new live-cart diagnostic is not passing evidence until an installed-runtime run executes the new commit. The orderable Chromium gate on `37303922abbd07a63c2c0d1790a92f3f5d844397` remains failed.

## Remaining work

Use the first failing live-cart invariant to make the smallest Core-compatible correction. If all live-cart/Core delivery-option assertions pass while the delivery template still renders no option, the defect moves to the OPC `CheckoutSession`/delivery presenter/rendering boundary. Native payment completion, successful Core-order reservation cleanup, failure/abandonment/TTL recovery and broader carrier/payment compatibility remain release blockers.

`INTEGRATION_SHELL_READY` stays `false`.
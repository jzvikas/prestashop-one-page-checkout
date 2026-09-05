# ADR-0017: Finalization preflight and native payment handoff

## Status

Accepted for implementation. Production checkout takeover remains disabled by `INTEGRATION_SHELL_READY=false` until the deferred installed-runtime/browser matrix is executed successfully.

## Context

The checkout shell already keeps cart, customer, address, carrier, payment and agreement state server-authoritative. The last browser action still needed a safe boundary between "the checkout appears ready" and handing control to a PrestaShop payment module.

Creating the order inside the OPC module would duplicate payment-module responsibilities and would be especially unsafe for redirect, embedded, binary/self-submitting and free-order paths. A browser-only double-click guard is also insufficient because concurrent tabs or retries can race.

PrestaShop 9.1.5 Core behavior relevant to this decision is:

- payment options are rediscovered through `PaymentOptionsFinder::present()` and `actionPresentPaymentOptions`;
- a zero-total cart is represented by Core's `free_order` payment option whose action points to `order-confirmation?free_order=1`;
- `OrderConfirmationController::checkFreeOrder()` performs the Core free-order validation/order creation and is duplicate-aware;
- binary options use the radio's `data-module-name` to expose `.js-payment-{moduleName}` and let that module-owned control perform the final action;
- ordinary payment forms are submitted through the observable submit lifecycle, allowing payment-module handlers to participate.

## Decision

### 1. Server finalization preflight

`CheckoutFinalizationMutation` is the only module endpoint that can reserve a final handoff attempt. It runs behind the normal CSRF/cart/customer/stale-state guard and cart mutex.

A `begin` request revalidates immediately before handoff:

- an order does not already exist for the cart;
- a loaded cart-bound customer exists;
- the cart is non-empty and satisfies minimum-purchase/orderability checks;
- delivery/invoice addresses still belong to the cart customer and pass Core `AddressValidator` checks;
- physical-cart carrier selection still exists in fresh Core delivery options;
- the persisted payment selection still exists in a fresh Core payment-option presentation;
- the exact fresh agreement set is still approved.

The reservation is scoped to shop + cart + state version + canonical payment selection + cryptographically random browser attempt ID. A different attempt cannot release it. The persistence layer provides the cross-tab/process duplicate barrier; the browser busy flag is only UX.

A `release` request may clear only its own attempt and still passes the ordinary mutation guard. Expired reservations are recoverable through the store TTL.

### 2. Ordinary payment handoff

After a successful reservation, `final-submit-controller.js` does not create an order. It hands control back to the selected Core-presented payment form.

Submission preserves module/browser lifecycle in this order:

1. jQuery `submit` trigger when available, matching the PrestaShop ecosystem's observable handler path;
2. `requestSubmit()` when available;
3. raw `HTMLFormElement.prototype.submit.call()` only as the final compatibility fallback.

Payment-form controls are not disabled before handoff. Other checkout controls are frozen while the reservation is active.

### 3. Binary/self-submitting payment handoff

`binary-payment-controller.js` owns binary options. The generic final button is hidden while a binary option is selected.

The adapter resolves the module-owned binary surface using the same Core identity contract: the selected option's `data-module-name` maps to `.js-payment-{moduleName}`. A conservative additional-information fallback is allowed only inside the selected payment section.

Click and form-submit activation are intercepted during capture phase. The module's original handler/default action is stopped until server preflight succeeds. On success, the exact original control/form is replayed; the adapter never synthesizes payment credentials or calls `validateOrder()`.

A successful reservation response is required to contain no section replacements. Replacing payment DOM immediately before replay could discard third-party runtime handlers/state, so such a response fails closed and releases the reservation.

The adapter mirrors Core agreement gating for the selected binary surface while preserving controls that were already disabled by the payment module for its own reason.

### 4. Free orders

No module-owned free-order creator is introduced.

`PrestaShopCheckoutPaymentOptionsPresenter` already calls Core `PaymentOptionsFinder::present($isFree)`. For a zero-total cart, the Core `free_order` option is therefore rendered through the same payment template. The ordinary native-form handoff submits the Core-provided `order-confirmation?free_order=1` action, leaving validation, duplicate detection, `PaymentFree::validateOrder()` and confirmation redirect to PrestaShop Core.

### 5. Lifecycle cleanup

Order lifecycle cleanup removes module-owned checkout selection and finalization-reservation rows after successful Core order creation. Uninstall removes both schemas. Abandoned rows remain bounded by ownership checks/reservation TTL and are not browser-authoritative state.

## Consequences

- The OPC module still does not own payment/order creation.
- Final eligibility is rechecked under the cart mutex immediately before native handoff.
- Cross-tab duplicate handoffs are rejected at the database reservation boundary.
- Ordinary, binary/self-submitting and free-order payment paths retain their native PrestaShop/module semantics.
- A browser crash after reservation can temporarily block a second attempt until reservation recovery/TTL, which is safer than risking a duplicate order.
- Real installed PrestaShop/browser verification is still mandatory before `INTEGRATION_SHELL_READY` may be enabled.

## Verification requirements before activation

The deferred runtime/browser matrix must prove at minimum:

- ordinary native form handoff with representative redirect and embedded payment modules;
- binary control click and binary form-submit replay with real third-party handlers;
- zero-total Core `free_order` completion and duplicate refresh behavior;
- stale-state and validation-failure section refreshes;
- same-cart concurrent tabs produce one active reservation;
- successful order hooks clear module selection/reservation rows;
- failed/abandoned payment flows recover without bypassing the final preflight;
- native `ps_onepagecheckout` conflict fallback and disabled-module fallback remain intact.

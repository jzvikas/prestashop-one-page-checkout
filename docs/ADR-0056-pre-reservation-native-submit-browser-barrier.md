# ADR-0056: Verify pre-reservation native payment submit blocking in Chromium

## Status

Accepted as a release-blocking native payment browser contract. `INTEGRATION_SHELL_READY` remains `false`.

## Context

Exact-head run `34065668822` on commit `7f9a652cadb1c68bdc799c214445c2fc76603e37` completed the real PrestaShop 9.1.5 native-payment path successfully: Chromium submitted the official pinned `ps_checkpayment` form, the payment module/Core created exactly one order for the OPC cart, Core reached order confirmation, and `actionValidateOrderAfter` cleanup removed both OPC selection and finalization-reservation state.

That proof closes the basic ordinary native-payment completion/cleanup milestone, but it does not prove that the preserved Core-presented payment form cannot be submitted directly before OPC finalization reservation is acquired. The source guard is intentionally browser-side defense in depth, so this boundary needs an executed browser contract rather than source assertions alone.

## Decision

The existing native-payment completion Chromium contract now performs one direct observable `requestSubmit()` against the selected Core-presented `ps_checkpayment` form before the OPC final-submit button is used.

The pre-reservation attempt must satisfy all of the following:

- no `ps_checkpayment` validation request may leave Chromium;
- the `jzopc:checkout:payment-submit-blocked` lifecycle event must be observed;
- no finalization-preflight or payment-handoff event may occur;
- no ambiguous-handoff event may occur;
- the checkout path must remain unchanged;
- no reservation-authorized state is synthesized by the test.

After that blocked attempt, the same browser checkout must still complete through the normal OPC final-submit button. The existing real finalization POST, reservation, guarded handoff, official payment-module POST, Core order confirmation, exactly-one-order probe and successful-order OPC cleanup remain mandatory. The authorized handoff must not generate an additional submit-guard rejection.

Lifecycle counters are retained in the Node process through the existing exposed binding. No payment request bodies, response bodies, cookies, CSRF tokens or customer payloads are logged.

## Security and ownership

This milestone changes runtime verification only. Production OPC code is unchanged.

The browser test does not invoke `PaymentModule::validateOrder()`, does not create an order, does not mock or fulfill the payment request and does not bypass Core payment handling. The official `ps_checkpayment` module and PrestaShop Core remain authoritative for order creation.

The test strengthens the browser proof for the ordinary-payment submit barrier without claiming that hostile low-level `HTMLFormElement.prototype.submit.call()` code can be made authoritative by client JavaScript. Representative Enter-key, jQuery/native third-party handlers, embedded/tokenization forms, binary/self-submitting modules, failure/retry and abandoned/TTL recovery remain separate release gates.

The readiness gate must remain closed until this browser contract has executed successfully on the exact head and the remaining compatibility gates are completed.

# ADR-0057 — Ambiguous native payment reservation runtime gate

## Status

Accepted as a release gate. `INTEGRATION_SHELL_READY` remains `false`.

## Context

The ordinary and binary payment adapters already treat the boundary immediately before module-owned native activation as irreversible from the browser's point of view. A synchronous exception after that boundary is ambiguous: third-party JavaScript may have started remote payment, tokenization, popup or other side effects before throwing. Releasing the finalization reservation in that state could authorize a second payment handoff for the same cart.

Source review alone was not sufficient. The installed browser/runtime matrix already proved the normal `ps_checkpayment` handoff through Core order creation and `actionValidateOrderAfter` cleanup, and the pre-reservation direct-form barrier was executed successfully on commit `1ab87e9a955327eeab4515f1574feb8985122984`. The next missing safety proof was the opposite path: a failure after reservation and after native activation has begun.

## Decision

The PrestaShop 9.1.5 Native Payment Runtime now contains a dedicated ambiguous-handoff scenario before the normal successful payment scenario.

`tests/Browser/native-payment-ambiguous-handoff-browser-contract.mjs` prepares a real orderable checkout through the same browser mutation endpoints used by the successful runtime contract: guest identity, Core address, Core carrier, pinned official `ps_checkpayment`, and current legal agreements.

Immediately before final submit, the test replaces only the selected Core-presented form's `requestSubmit()` method with a test-local function that throws synchronously. This injection is intentionally after checkout setup and is confined to the disposable Chromium page. It does not alter the module, PrestaShop Core, the payment module, server state, payment fields, cookies, CSRF values, or order data.

The browser must then prove all of the following:

- finalization preflight completed and the server reservation was acquired;
- the native payment handoff boundary was crossed;
- the synchronous activation failure produced `jzopc:checkout:payment-handoff-ambiguous`;
- the ambiguity guard produced `jzopc:checkout:payment-handoff-locked`;
- no `ps_checkpayment` validation request escaped;
- the checkout root is marked ambiguous/busy and all normal form controls are disabled;
- after restoring the original form method, a direct native form retry is still suppressed by the locked checkout;
- a second final-submit activation cannot issue another finalization request.

The browser returns only the positive Core cart ID. It does not return cookies, customer data, CSRF material, the reservation attempt ID, payment fields, or response bodies.

`tests/Runtime/AmbiguousPaymentReservationContract.php` then inspects the installed MariaDB state for that cart and requires:

- zero Core orders;
- exactly one still-active `jzopc_checkout_finalization` row with the original shop/customer/state/payment/attempt bindings present;
- exactly one canonical `jzopc_checkout_selection` row.

The probe is read-only for the browser cart. It does not call `PaymentModule::validateOrder()`, create an order, release the reservation, shorten the TTL, or mutate Core checkout business state.

The existing installed `FinalizationReservationMariaDbContract.php` remains the authoritative database-time expiry/replacement proof: it forces only synthetic module-owned reservation data past expiry and verifies that a replacement attempt becomes possible only after expiry. The ambiguity browser gate therefore does not sleep for 15 minutes or weaken the production TTL merely to make CI faster.

After the ambiguity assertions, the workflow creates a separate browser cart and executes the existing official `ps_checkpayment` success path. That second path must still reach Core order confirmation and `CompletedOrderCleanupContract.php` must still prove exactly one Core-created order plus removal of both OPC transient rows.

## Security properties

This gate preserves the fail-closed rule that ambiguity is safer than duplicate handoff. Browser cleanup is allowed only before native activation is known to have started. Once the selected module's activation API is invoked, an exception cannot be interpreted as proof that no external side effect occurred.

The DB reservation remains the cross-tab/process authority. The browser lock is defense in depth and prevents accidental in-page retry while the reservation remains active. Recovery remains either successful Core order cleanup or the bounded database-time reservation TTL.

No production code or order-creation ownership changes are introduced by this ADR. PrestaShop payment modules/Core continue to own order creation.

## Verification status

The source, browser contract, installed-state probe, workflow wiring and smoke contract are committed. They must not be described as runtime-green until an exact-head Native Payment Runtime execution completes successfully. CI/source checks and runtime results are recorded separately from source implementation.

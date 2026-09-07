# ADR-0058: Ambiguous native payment TTL recovery runtime gate

## Status

Accepted as a release-readiness verification boundary. `INTEGRATION_SHELL_READY` remains `false`.

## Context

ADR-0057 established the fail-closed behavior for a native payment handoff that becomes ambiguous after finalization reservation acquisition: the browser freezes checkout, no second finalization/native payment attempt may escape, and the server reservation remains active because the third-party module may already have produced external side effects.

Executed Native Payment Runtime run `34071667586` on commit `bbb36a7998d42a63d9219740d817abdb187abce1` verified that ambiguity barrier on PrestaShop 9.1.5. The remaining release question is whether the same real browser cart can recover after the bounded reservation TTL without weakening the production TTL, releasing an active ambiguous attempt early, changing cart/customer authority, or creating an order in OPC code.

## Decision

The Native Payment Runtime now extends the real ambiguous `ps_checkpayment` cart through the expiry boundary.

1. The ambiguity Chromium contract stores its browser storage state only at `/tmp/jzopc-ambiguous-browser-state.json` and immediately changes the file mode to `0600`.
2. The workflow never prints, uploads or archives that file and removes it in an `always()` cleanup step.
3. `AmbiguousPaymentReservationExpiryControl.php` is CLI-only test infrastructure. It refuses execution unless:
   - `JZOPC_RUNTIME_ACTIVE_FIXTURE=1`;
   - the installed shop resolves exactly to `/tmp/prestashop`;
   - the installed module resolves to `/tmp/jzopc-active-fixture*`;
   - the runtime is PrestaShop 9.1;
   - the browser cart is real, customer-bound and has no Core order.
4. The control changes only the matching module-owned `jzopc_checkout_finalization.expires_at` value and uses MariaDB server time. It does not shorten the production reservation TTL, alter Core cart/order/payment data, or release an active reservation by application API.
5. A second Chromium process loads the protected ephemeral session, navigates to `/order`, requires the exact same Core cart ID, requires the expired reservation to render inactive, and requires the old client ambiguity lock not to survive the fresh document.
6. The recovered checkout must retain the canonical `ps_checkpayment` and legal selections and complete the normal OPC finalization reservation → native payment module validation → Core order-confirmation path.
7. `CompletedOrderCleanupContract.php` must then prove Core/payment-module ownership and removal of OPC transient reservation/selection state for that same recovered cart.
8. The existing clean-cart native payment completion gate remains in the workflow after recovery, so the recovery fixture cannot replace ordinary success-path coverage.

## Security properties

The recovery gate does not introduce a production recovery endpoint, browser-controlled expiry, reservation release token, or direct order creation path. Browser cookies/session material remain ephemeral test-runner state and are never sent to stdout. The expiry control is structurally incapable of targeting a normal source/production module installation.

Production behavior remains fail-closed: an ambiguous post-activation handoff stays blocked until Core order cleanup or natural database-time TTL expiry. A reload before expiry remains subject to the active reservation barrier; only an actually expired row may be replaced by a fresh finalization attempt.

## Verification requirement

This ADR is not considered runtime-verified merely because the source exists. Exact-head CI must execute syntax/smoke contracts, and Native Payment Runtime must execute the real PrestaShop 9.1.5 ambiguity → expiry → same-cart native payment → Core order → OPC cleanup sequence successfully.

No result may be described as green until those workflow runs have completed successfully.

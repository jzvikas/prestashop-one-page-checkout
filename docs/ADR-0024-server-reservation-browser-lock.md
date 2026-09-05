# ADR-0024: Server-reservation browser lock and native-handoff ambiguity lifecycle

## Status

Accepted for source implementation. `INTEGRATION_SHELL_READY=false` remains unchanged. Installed/runtime and controlled browser verification are still required before production checkout takeover can be reconsidered.

## Context

The DB-backed finalization reservation is the cross-tab/process authority between successful OPC preflight and the native PrestaShop/payment-module handoff. The shell now exposes only a server-generated boolean indicating whether an unexpired reservation already exists for the current cart/customer.

Two browser gaps remained after the reservation recovery hardening:

1. after a page reload, the server reservation survived but the new DOM could present enabled checkout/payment controls until another request was rejected with `finalization_in_progress`;
2. the merged binary controller had regressed the native-activation boundary by treating every JavaScript `AbortError` as a harmless preflight abort, even if a third-party module threw an error with that name after its click/submit handler had already started.

The browser is not security authority, so neither gap weakens the DB reservation itself. They do, however, create an unsafe retry surface and can obscure the fact that payment work may already be progressing.

## Decision

1. The trusted checkout root carries `data-jzopc-finalization-reserved="0|1"`, derived from the server reservation store. No attempt ID, payment payload, token or expiry value is exposed.
2. `payment-handoff-ambiguity-guard.js` locks the checkout immediately on page load when that server-owned marker is `1`.
3. A guarded server rejection containing machine code `finalization_in_progress` sets the local boolean marker to `1` and schedules the same lock after the current controller cleanup microtask. This prevents synchronous failure cleanup from re-enabling controls afterwards.
4. The lock disables native form controls and capture-blocks click/form-submit activation. Link/role-button payment activators are marked `aria-disabled` and removed from the tab order.
5. Ordinary and binary adapters publish `jzopc:checkout:payment-handoff-ambiguous` only after native module-owned activation has begun and a synchronous exception makes local recovery uncertain. The shared guard performs the final lock after their synchronous cleanup.
6. Binary preflight failures publish the same `jzopc:checkout:validation-failed` lifecycle used by the generic mutation/final-submit clients, allowing `finalization_in_progress` to converge on one browser lock behavior.
7. Binary `AbortError` is considered a harmless request cancellation only while native activation has **not** started. After activation begins, an `AbortError` is treated as an ambiguous third-party handler failure and cannot reopen or silently unlock the checkout.
8. The shared guard is loaded after payment, mutation, final-submit, ordinary-submit and binary controllers. It does not rewrite or disable native payment form fields before a handoff starts.
9. The DB reservation and Core order state remain security authority. Browser locking is defense in depth and UX protection only.
10. No order is created by the guard or adapters; `PaymentModule::validateOrder()` remains outside OPC browser/module code.
11. No schema/config/hook migration is introduced, so the module version remains `0.4.0`.

## Compatibility consequences

- native payment forms remain fully enabled until the module-owned handoff actually begins, preserving hidden successful controls, embedded fields and tokenization integrations;
- ordinary direct-submit protection remains owned by `ordinary-payment-submit-guard.js` and is unaffected;
- binary module click/form replay remains the exact original control/form;
- a reload during a live reservation deliberately presents a temporarily locked checkout rather than an apparently retryable one;
- after reservation expiry or successful Core order cleanup, a fresh server render returns marker `0` and normal interaction is restored.

## Security consequences

The change prefers bounded temporary unavailability over presenting a second payment attempt while another handoff may still be progressing. A hostile script can ignore browser UI state, but it still cannot bypass the server reservation/cart/order guards through these client changes.

No browser lifecycle event contains CSRF tokens, attempt IDs, payment credentials or form payloads.

## Verification

`CheckoutFinalSubmitBrowserContractSmokeTest.php` records the ordinary/binary ambiguous-handoff source boundary and shared guard behavior. `CheckoutFinalizationReservationBrowserGuardContractSmokeTest.php` additionally locks the reload marker, `finalization_in_progress` convergence, link/form suppression and post-activation `AbortError` rule.

GitHub Actions source/smoke checks must pass for this delta, followed by the installed PrestaShop 9.0/9.1/9.2 matrix. Controlled browser coverage must still prove reload during an active reservation, same-cart competing tabs, representative ordinary/binary handlers that throw after starting work, link-style binary activators, successful Core cleanup and TTL recovery. Until those browser gates pass, `INTEGRATION_SHELL_READY` stays `false`.

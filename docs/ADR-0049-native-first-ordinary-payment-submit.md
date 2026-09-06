# ADR-0049: Native-first ordinary payment submit lifecycle

## Status

Accepted for runtime verification. `INTEGRATION_SHELL_READY` remains `false`.

## Context

The first executed PrestaShop 9.1.5 native-payment completion runtime gate reached an installed active checkout with a valid Core carrier and the pinned official `ps_checkpayment` option, completed the OPC mutation sequence and entered final-submit, but timed out waiting for Core order confirmation. The Front Office server log contained no request to the `ps_checkpayment` validation controller, so the failure occurred before payment-module order ownership began. The post-order cleanup probe was correctly skipped because no Core order had been observed.

The final-submit controller previously preferred `window.jQuery(form).trigger('submit')` after the server finalization reservation was granted. That path relies on jQuery synthetic-submit default-action semantics even when the browser supports the standards-native form submission lifecycle.

## Decision

For ordinary, non-binary Core-presented payment forms, the final-submit controller now prefers `HTMLFormElement.requestSubmit()` after the exact selected form has been authorized by the existing one-submit reservation guard.

This preserves the important boundaries:

- the server finalization preflight and cart reservation still happen first;
- the exact selected Core-presented payment form remains the handoff target;
- a real native `submit` event is observable by DOM listeners and jQuery handlers registered on that form;
- the ordinary-payment submit guard consumes its exact one-submit authorization on that observable native event;
- jQuery synthetic submit remains only a compatibility fallback when `requestSubmit()` is unavailable;
- direct `HTMLFormElement.prototype.submit.call(form)` remains the final legacy fallback;
- once the module-owned submit lifecycle starts, the reservation is not optimistically released because remote payment/order side effects may already be ambiguous;
- neither the controller nor the runtime harness calls `validateOrder()` or creates an order directly.

## Verification

Source smoke coverage locks native `requestSubmit()` ahead of the jQuery fallback. Release verification additionally requires an executed PrestaShop 9.1.5 browser run to reach the pinned `ps_checkpayment` validation flow, Core order confirmation, and the post-order DB probe proving exactly one Core order plus zero OPC finalization/selection rows for that cart.

Until that executed result is green, native payment completion and post-order cleanup remain unverified and `INTEGRATION_SHELL_READY=false`.

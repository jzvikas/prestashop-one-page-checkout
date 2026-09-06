# ADR-0050: Action-only PaymentOption native handoff boundary

## Status

Accepted for runtime verification. `INTEGRATION_SHELL_READY` remains `false`.

## Context

Two executed PrestaShop 9.1.5 native-payment runtime attempts reached successful OPC final preflight with the pinned official `ps_checkpayment` option but did not navigate into Core order confirmation. The second attempt already preferred the browser-native `requestSubmit()` lifecycle, so the failure was not resolved by replacing jQuery synthetic submission.

PrestaShop 9.1 Core distinguishes payment options that provide their own form markup from options that provide only `PaymentOption::setAction()`. The official `ps_checkpayment` fixture is action-only: its payment contract is the Core-presented validation URL plus successful form controls. OPC already mirrors Core presentation by generating a thin form only when `option.form` is absent, while preserving `option.form` raw markup untouched when a module owns the form.

## Decision

Mark only OPC-generated action-only forms with `data-jzopc-payment-action-form="1"`.

The ordinary payment guard still requires the same exact server-reserved handoff authorization and an observable submit event. Once that one authorization is consumed:

- for an OPC-generated action-only form, the guard stops the observable event and immediately calls the platform-native `HTMLFormElement.prototype.submit` on that exact form, crossing directly into the Core-presented payment action;
- for module-provided `option.form` markup, the marker is absent and the full native/jQuery submit lifecycle remains untouched so module listeners, tokenization, embedded widgets and third-party integration code keep ownership;
- unreserved direct submission remains blocked in capture phase;
- binary/self-submitting options remain outside this ordinary-form path;
- ambiguous handoff still keeps the reservation until Core order cleanup or TTL recovery;
- OPC never calls `validateOrder()` and never creates an order directly.

This is deliberately narrower than bypassing submit handlers globally. It applies only to the form OPC itself had to synthesize from Core's action-only payment option representation.

## Verification

Source smoke coverage locks the marker to the template's action-only branch and requires direct submission only after exact reservation authorization. The release gate still requires an executed PrestaShop 9.1.5 browser run to reach `ps_checkpayment` validation, Core order confirmation, exactly one Core order for the cart, and zero OPC finalization/selection rows after `actionValidateOrderAfter` cleanup.

Until that runtime result is genuinely green, native payment completion remains unverified and `INTEGRATION_SHELL_READY=false`.

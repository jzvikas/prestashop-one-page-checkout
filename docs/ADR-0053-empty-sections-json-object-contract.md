# ADR-0053: Empty checkout sections are a JSON object-map contract

## Status

Accepted for the current production-hardening milestone. `INTEGRATION_SHELL_READY` remains `false`.

## Context

The executed PrestaShop 9.1.5 native-payment runtime on `f9c410ed16b2e4d962363c0f24dc06fccc81a7bf` reached a successful server-side finalization preflight but never emitted the browser `jzopc:checkout:final-preflight-completed` or `jzopc:checkout:payment-handoff` lifecycle events and never sent the official `ps_checkpayment` validation request.

Source review identified a transport-shape mismatch at the exact post-preflight boundary. A successful `CheckoutFinalizationMutation` intentionally returns no refreshed DOM sections after the reservation is acquired. Internally that is represented as PHP `[]`. `CheckoutJsonResponse::toJson()` previously serialized the empty PHP array as JSON `[]`, while the browser mutation contract correctly requires `sections` to be an object/map and rejects JSON arrays. The browser therefore treated the otherwise successful preflight response as malformed, ran best-effort attempt release, and never transferred control to the Core-presented payment form.

## Decision

`CheckoutJsonResponse::toJson()` normalizes only the `sections` transport field from its internal `array<string,string>` representation to an object before JSON encoding.

Consequences:

- non-empty section maps continue to serialize as JSON objects;
- an empty section map serializes as `{}` instead of `[]`;
- `errors` remains a JSON list and is not affected by the normalization;
- internal PHP domain/result types remain arrays, so no renderer/orchestrator contract is changed;
- the browser remains strict and fail-closed: `sections` arrays are still rejected rather than accepted as a compatibility workaround;
- successful finalization can proceed from the server-owned reservation into the existing native payment handoff without weakening CSRF/cart/customer/state binding or reservation semantics.

## Regression contract

`CheckoutMutationResponseMapperSmokeTest.php` now verifies the encoded JSON shape for:

1. a non-empty rendered section map;
2. a successful mutation with zero refreshed sections;
3. a validation failure with zero refreshed sections;
4. a guard/busy response with zero refreshed sections;
5. preservation of `errors` as a JSON list.

The representative native payment runtime remains authoritative for proving the complete chain: final submit -> reservation -> official payment-module validation -> Core order creation -> `actionValidateOrderAfter` cleanup. This ADR does not mark that runtime green until it is executed successfully on the exact implementation.

## Security and ownership

This change does not create orders, submit payment data itself, call `PaymentModule::validateOrder()`, relax browser response validation, alter the cart mutex, or release reservations after native payment ownership begins. It fixes only the deterministic JSON type at the server/browser transport boundary.

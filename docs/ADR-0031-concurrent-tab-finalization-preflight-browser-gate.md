# ADR-0031 — Concurrent-tab finalization preflight browser gate

## Status

Accepted for the PrestaShop 9.1.5 production-hardening milestone. The gate is committed but not yet executed because GitHub Actions quota is currently exhausted.

## Context

The reservation store already has sequential and independent-process MariaDB contention contracts, but the release checklist still requires browser evidence for same-session, same-cart tabs. A full successful payment-handoff contention scenario needs a checkout fixture with valid identity, address, carrier, agreements and a representative payment module. Before that larger fixture is introduced, the HTTP boundary must prove that two simultaneous browser attempts cannot acquire or leak a reservation when authoritative preflight is still invalid.

This matters because reservation acquisition must happen only after final preflight succeeds. If acquisition accidentally moved earlier, one incomplete tab could create a temporary payment-handoff barrier and make the second tab observe `finalization_in_progress` even though neither checkout is orderable.

## Decision

Add `tests/Browser/finalization-concurrent-tabs-preflight-browser-contract.mjs` and execute it only in the PrestaShop 9.1.5 runtime job.

The contract:

- creates one real Core cart through `/cart`;
- opens two Chromium pages in the same browser context so they share the same PrestaShop session and cart;
- requires both tabs to render the same authoritative cart and state version with no reservation;
- sends two concurrent `finalizationAction=begin` requests with distinct cryptographically random attempt IDs and each tab's trusted CSRF/cart/state bindings;
- requires both responses to fail closed with `customer_required` rather than `finalization_in_progress`;
- reloads both tabs and requires the same Core cart plus `data-jzopc-finalization-reserved="0"` in each tab;
- fails on browser JavaScript errors or malformed server responses.

The contract never calls a payment module, never creates an order and never calls `validateOrder()`.

## Consequences

This adds real same-session concurrent HTTP/browser coverage for the boundary before reservation acquisition and protects the ordering invariant `preflight -> reservation -> native payment handoff`.

It does **not** satisfy the full concurrent-finalization release blocker. A later browser gate must prepare a genuinely orderable checkout and prove that one tab acquires the reservation/native handoff while a competing tab receives `finalization_in_progress`, followed by exact release, Core-order cleanup and TTL recovery scenarios.

`INTEGRATION_SHELL_READY` remains `false` until those stronger runtime/browser gates and the remaining payment/carrier/account release requirements are executed successfully.

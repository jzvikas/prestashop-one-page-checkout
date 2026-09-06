# ADR-0033 — Orderable concurrent-tab finalization browser gate

## Status

Accepted for the PrestaShop 9.1.5 production-hardening milestone. The gate is committed and source-reviewed but has not been executed because GitHub Actions quota remains exhausted.

## Context

ADR-0031 added a two-tab Chromium contract for an intentionally incomplete checkout and proved that authoritative preflight rejects both attempts before reservation acquisition. The remaining higher-value gap is the opposite boundary: once a real checkout is orderable, two same-session tabs must not both receive permission to cross into a native payment handoff.

The production reservation store already has MariaDB sequential and independent-process contention contracts, but those do not prove that the complete browser/Core mutation path produces the same result after guest identity, address, carrier, payment and legal-condition state have been established through the active OPC shell.

A deterministic payment option is required for the runtime shop because the installed PrestaShop fixture uses `--fixtures=0`. The 9.1.5 job therefore installs the official `PrestaShop/ps_checkpayment` module pinned to commit `163eea350e29616f7cff343285d8c4bcc2b6cc44`, configures its non-secret payee/address values and enables guest checkout only inside the disposable installed-runtime environment. The payment module remains the owner of any eventual order creation; this contract deliberately stops before its native form is submitted.

## Decision

Add `tests/Browser/finalization-orderable-concurrent-tabs-browser-contract.mjs` and execute it only in the PrestaShop 9.1.5 runtime job.

The browser contract:

- creates a real Core cart through `/cart`;
- completes guest identity through the Core-rendered customer form and OPC identity mutation endpoint;
- creates a delivery address through the Core-rendered address form, retains the invoice binding, and uses the normal OPC address mutation path;
- selects a real Core delivery option;
- selects the pinned official `ps_checkpayment` payment option through `PaymentOptionsFinder`/OPC selection handling;
- approves every currently rendered required agreement as one exact set;
- opens a second tab in the same browser context and requires both tabs to share the same Core cart and authoritative state version;
- issues two distinct `finalizationAction=begin` requests from the two tabs;
- requires exactly one attempt to succeed and exactly one competing attempt to fail with `finalization_in_progress`;
- replays the winning attempt and requires idempotent success;
- sends a release for the losing/foreign attempt and then reloads both tabs, requiring `data-jzopc-finalization-reserved="1"` to prove the active barrier was not cleared;
- releases the exact winning attempt, reloads both tabs and requires `data-jzopc-finalization-reserved="0"`;
- fails on malformed JSON, server 5xx responses or browser JavaScript errors.

A source smoke contract locks the 9.1-only workflow wiring, pinned official payment fixture, full browser preparation path, competing-attempt error, exact replay/release sequence and closed production readiness gate.

## Safety properties

- The OPC module does not call `PaymentModule::validateOrder()` and this contract never invokes the payment form or payment validation controller.
- No test-only payment/order endpoint is added to production source.
- Browser values remain binding/selection requests; finalization still revalidates authoritative Core cart, customer, addresses, carrier, payment and agreements under the cart mutex before reservation acquisition.
- The losing tab cannot clear the winning reservation because release remains exact customer/attempt scoped.
- The same winning attempt remains idempotent while the reservation is active.
- The runtime-only `ps_checkpayment` setup is scoped to the PrestaShop 9.1.5 job and does not alter supported production activation behavior.
- `INTEGRATION_SHELL_READY` remains `false`.

## Verification

`node --check` was executed locally against the new browser source and reported no syntax error. `php -l` was executed locally against `CheckoutOrderableConcurrentTabsBrowserRuntimeContractSmokeTest.php` and reported no syntax error.

The actual Chromium/PrestaShop/MariaDB gate, smoke suite and GitHub Actions workflow were **not executed** in this delta and must not be described as passing until quota is available and the workflow has produced an executed result.

## Consequences

This closes an important source/test-coverage gap between incomplete-preflight rejection and the native payment activation boundary. Once executed successfully, it will provide browser evidence that a fully orderable same-cart checkout grants at most one active payment-handoff reservation and preserves exact-attempt ownership through replay and release.

It does not yet prove native payment submission, successful Core order cleanup, TTL expiry/retry, slow or abandoned external payment recovery, thrown/partial third-party handlers, binary/embedded modules or zero-total completion. Those remain release blockers, so production takeover stays closed.

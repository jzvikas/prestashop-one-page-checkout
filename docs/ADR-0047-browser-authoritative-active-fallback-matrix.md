# ADR-0047: Browser-authoritative active checkout fallback matrix

## Status

Accepted for the disposable installed-runtime harness. Production checkout readiness remains closed.

## Context

Executed PrestaShop 9.0.3 and 9.1.5 installed-runtime jobs proved that the same disposable shop/server can serve a healthy active OPC checkout to real Chromium. The 9.1.5 job additionally completed the fully orderable same-session two-tab finalization-reservation contention gate with official `ps_checkpayment`, while deliberately stopping before native payment submission.

A later standalone PHP/cURL fallback harness continued to fail before any injected failure. Its initial `GET /order` resolved as HTTP 200 but libcurl reported zero downloaded body bytes. The identical shop/server had already rendered the active checkout successfully to Chromium in the same job. Repeated cURL handle, cookie and body-capture hardening did not turn that transport-specific symptom into checkout evidence.

The release requirement is not that a custom PHP HTTP client reproduce browser transport internals. The requirement is that a real browser using one Core cart/session sees:

1. healthy OPC takeover;
2. request-local native Core fallback when a controlled integration dependency fails;
3. no OPC JavaScript initialization on the fallback page;
4. recovery to healthy OPC after the injected failure is removed;
5. the same Core cart identity across the sequence.

## Decision

The active failure matrix is authoritative in `tests/Browser/active-checkout-browser-contract.mjs`, using one Playwright Chromium context and page.

The matrix covers four controlled failure boundaries:

- checkout-selection persistence schema unavailable;
- shell service exception;
- Smarty checkout template failure;
- checkout shell asset-manifest registration exception.

Service/template/assets failures remain marker-driven only inside the instrumented `/tmp/jzopc-active-fixture*` copy. Persistence failure is controlled by `tests/Runtime/ActiveCheckoutPersistenceFailureControl.php`. That helper:

- requires `JZOPC_RUNTIME_ACTIVE_FIXTURE=1`;
- accepts only `/tmp/prestashop` as the installed shop root;
- verifies that the installed module resolves to `/tmp/jzopc-active-fixture*`;
- invokes only `CheckoutServerSelectionsSchema::uninstall()` / `install()`;
- never handles browser cookies, CSRF tokens, form payloads or customer data;
- never initiates finalization, payment submission or order creation.

For every failure the browser must render native Core checkout without the OPC root and without OPC initialization. After cleanup the same browser context must render healthy OPC with the exact original Core cart ID.

The standalone `ActiveCheckoutFallbackHttpContract.php` remains in the repository as diagnostic/source history, but the installed-runtime workflow no longer treats its PHP/cURL transport as the active fallback release gate.

## Safety properties

This change does not weaken checkout safety or replace server authority:

- production `INTEGRATION_SHELL_READY` remains `false`;
- only the disposable copied module may be instrumented or temporarily opened;
- browser cookies remain owned by Playwright/PrestaShop rather than being exported into fixture control code;
- Core checkout remains the required fallback surface;
- schema and marker cleanup run through outer `finally` boundaries;
- production renderer/service/asset sources remain marker-free;
- no payment form is submitted and no `PaymentModule::validateOrder()` call is added;
- native payment modules/Core remain the only owners of order creation.

Using the browser as the authority strengthens the runtime contract because it verifies the user-visible checkout lifecycle directly instead of accepting a transport-specific surrogate that had already diverged from proven Chromium behavior.

## Verification

The source/smoke contracts must require the browser fallback matrix, disposable persistence-control boundary, same-cart recovery and removal of the standalone PHP fallback invocation from the runtime workflow.

The implementation is not considered runtime-verified until an exact-head PrestaShop 9.0.3/9.1.5 installed-runtime execution passes the new Chromium matrix. Existing successful finalization-reservation evidence does not by itself satisfy this new fallback gate.

`INTEGRATION_SHELL_READY` must remain `false` after this milestone. Native payment completion, Core-created-order cleanup, duplicate refresh/failure/abandonment/TTL recovery and representative payment-module compatibility remain later release blockers.

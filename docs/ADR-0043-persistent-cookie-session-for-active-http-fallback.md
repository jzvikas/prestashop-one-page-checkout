# ADR-0043: Preserve one libcurl cookie session across active HTTP fallback requests

## Status

Accepted for the disposable installed-runtime harness. Production checkout readiness remains closed.

## Context

PrestaShop 9.1.5 runtime run `34035170745` on `1d6fa916be272853aeaf00d8007ba63f0c25ee7f` provided executed evidence that the active browser path itself is healthy through the current reservation milestone:

- active Chromium takeover/assets/identity validation passed;
- finalization preflight and concurrent preflight passed;
- the fully orderable two-tab gate passed with guest identity, Core address, Core carrier, official pinned `ps_checkpayment`, one reservation winner, one `finalization_in_progress` loser, idempotent replay and exact release;
- the post-browser live cart delivery-state diagnostic passed.

Only the later PHP/cURL active fallback contract failed, before any failure injection, because its newly seeded session did not render the initial healthy OPC root.

ADR-0042 had already aligned cart creation with the browser-proven Core `/cart?add=1&ajax=1` mutation, but the PHP harness still created a brand-new cURL handle for every request. Cookie continuity therefore depended on serializing cookies to a temporary jar when one handle closed and reading that jar back into the next handle. The green Chromium contracts instead retain one in-memory browser cookie store throughout their sequence.

The fallback property being tested is request-local OPC failure containment on one real Core cart/session. Disk cookie-jar flush timing is not part of that property and must not be allowed to create a false native-checkout baseline.

## Decision

`ActiveCheckoutFallbackHttpContract.php` now owns one `ActiveCheckoutHttpSession` for the complete sequence.

- One `CurlHandle` is created before Core cart seeding and reused for cart add, healthy `/order`, every injected failure, and every recovery request.
- `CURLOPT_COOKIEFILE => ''` activates libcurl's in-memory cookie engine.
- `CURLOPT_COOKIEJAR` remains enabled only as a disposable diagnostic/cleanup artifact; correctness no longer depends on reopening that file between requests.
- Immediately after Core AJAX cart seeding, the harness requires libcurl to contain at least one cookie before it is allowed to interpret `/order` output.
- The same browser-like user agent and real Core CartController AJAX mutation remain in place.

No production module code, activation rule, cart row, customer/address/carrier/payment selection, payment form or order-creation path is changed. The harness still never calls `PaymentModule::validateOrder()` and never writes Core cart/order SQL directly.

A dedicated source smoke contract requires exactly one cURL initialization and proves that cart seed, healthy checkout, injected failures and recoveries all use the same session object.

## Consequences

The active fallback test now models the same essential session property as a browser: cookie state is retained in memory across the whole checkout sequence. If Core cart seeding does not establish cookie state, the test fails before claiming anything about OPC takeover or fallback.

This is a test-infrastructure correctness change, not evidence that the fallback runtime milestone has passed. The exact-head PrestaShop 9.1.5 runtime gate must execute successfully before `healthy OPC -> injected failure -> native Core fallback -> same-cart recovery` is treated as verified.

`INTEGRATION_SHELL_READY` remains `false`.
# ADR-0045: Isolate fallback HTTP transfers while carrying only Core session cookies

## Status

Accepted for the runtime harness. This does not change production checkout behavior and does not open `INTEGRATION_SHELL_READY`.

## Context

The executed PrestaShop 9.1.5 runtime proved active Chromium takeover, identity/address/carrier/payment preparation and fully-orderable two-tab finalization reservation contention, but the later PHP/cURL fallback contract reported an initial `/order` response with HTTP 200 and zero captured/libcurl body bytes. The development-server trace showed the corresponding connection being accepted and closed without a normal completed `/order` request line. Repeated explicit resetting of `CURLOPT_NOBODY`, `CURLOPT_HEADER`, `CURLOPT_RETURNTRANSFER` and `CURLOPT_HTTPGET` did not remove the symptom.

A long-lived `CurlHandle` was therefore an unnecessary transport-state variable in a test whose real invariant is browser-session continuity, not TCP/libcurl-handle continuity.

## Decision

`ActiveCheckoutFallbackHttpContract.php` creates a fresh `CurlHandle` for every cart/checkout transfer. Each request explicitly selects body-bearing GET semantics and captures bytes through a write callback. The session object preserves only libcurl's structured cookie list (`CURLINFO_COOKIELIST`) and imports those cookies into the next fresh handle through `CURLOPT_COOKIELIST`.

The harness does not serialize cookie values to logs or a disk jar. It still proves that Core cart seeding established cookie state before `/order`, and the same carried Core session is used for healthy checkout, persistence/service/template/assets failure injection and recovery.

## Security and ownership constraints

- Production OPC code is unchanged.
- The harness never calls `PaymentModule::validateOrder()` and never creates orders directly.
- CSRF/cart/customer/finalization ownership remains in the existing production/Core paths.
- Failure diagnostics remain structural only and must not print response bodies, cookie values, tokens or customer data.
- Failure injection remains restricted to the disposable `/tmp/jzopc-active-fixture*` module copy.
- `INTEGRATION_SHELL_READY=false` remains mandatory until the final-submit/native-payment and required runtime/browser gates are genuinely proven.

## Verification requirement

Source smoke contracts must lock fresh-handle isolation, explicit cookie-list carry, body capture and no-order-creation boundaries. The milestone is not runtime-verified until an executed installed PrestaShop runtime proves healthy OPC -> injected native Core fallback -> same-cart/session OPC recovery. Unexecuted or queued jobs are not green evidence.

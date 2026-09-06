# ADR-0046: Use standard libcurl returned-body semantics for isolated fallback requests

## Status

Accepted for the disposable installed-runtime harness pending executed runtime verification. Production checkout behavior is unchanged and `INTEGRATION_SHELL_READY` remains `false`.

## Context

Runtime run `34050654501` on `fbcad5d10ca598d9c95f2793ba987c37a7d57d0c` provided matching evidence on the active PrestaShop 9.0.3 and 9.1.5 families. Their real browser checkout gates completed successfully before the later PHP/cURL fallback contract: 9.0.3 passed active Chromium takeover and finalization rejection, while 9.1.5 additionally passed the fully orderable two-tab reservation-contention contract with Core guest identity, address, carrier, official `ps_checkpayment`, one reservation winner, one blocked competitor, idempotent replay and exact release.

The fallback harness then failed on its first healthy `/order` in both families with HTTP 200 and an effective `/order` URL, but both the custom `CURLOPT_WRITEFUNCTION` capture and libcurl `CURLINFO_SIZE_DOWNLOAD` reported zero body bytes. No OPC failure had yet been injected. The 9.2.0-beta.1 job in that matrix is a native-OPC conflict scenario (`native_opc != 0`), so it deliberately skips the active fallback contract and is not evidence for or against this transport issue.

ADR-0045 already removed long-lived CurlHandle state by creating a fresh handle per request while carrying only libcurl's structured cookie list. The remaining material difference from the separately executed green fail-closed HTTP harness was body ownership: the active contract still installed a custom write callback, while the fail-closed contract used libcurl's standard returned-body mode.

Because the same installed Front Office server and fixture were already proven through Chromium, changing production OPC takeover/payment/carrier/finalization code would be speculative. The next isolation step therefore stays entirely inside test infrastructure.

## Decision

Each `ActiveCheckoutHttpSession::request()` now:

- initializes a fresh handle with the target URL;
- explicitly requests a body-bearing GET (`CURLOPT_NOBODY=false`, `CURLOPT_HEADER=false`, `CURLOPT_HTTPGET=true`);
- uses `CURLOPT_RETURNTRANSFER=true` and requires `curl_exec()` to return a string body;
- does not install `CURLOPT_WRITEFUNCTION`;
- preserves only the structured libcurl cookie list across fresh handles, exactly as ADR-0045 requires;
- records `CURLINFO_EFFECTIVE_METHOD` when the linked libcurl exposes it and fails closed if the effective method is not GET;
- retains structural status/path/content-type/download-size/content-length/checkout-marker diagnostics without logging response bodies, cookie values, tokens or customer data.

Core `/cart?add=1&ajax=1` remains the only cart seeding path for this contract. Healthy OPC must still be observed before persistence/service/template/assets failures are injected, and every injected failure must still render native Core checkout followed by same-session OPC recovery.

## Security and ownership invariants

- Production OPC source is unchanged.
- The harness never calls `PaymentModule::validateOrder()` and never creates an order directly.
- It does not write Core cart/order SQL or manufacture payment/carrier state.
- CSRF, cart/customer binding, stale-state protection, cart mutex semantics and native payment ownership remain in the production/Core paths.
- Failure injection stays confined to `/tmp/jzopc-active-fixture*`.
- Diagnostics remain structural only; response bodies, cookies, tokens, form payloads and customer data are not emitted.
- `INTEGRATION_SHELL_READY=false` remains the production source authority.

## Verification requirement

Source smoke contracts must require the standard returned-body path, fresh-handle isolation, structured cookie carry, effective-GET checking and the no-order-creation boundary. This ADR is not a runtime-pass claim. The milestone becomes verified only after an executed active PrestaShop runtime proves `healthy OPC -> injected persistence/service/template/assets native Core fallback -> same-cart/session OPC recovery`. If the transfer still reports zero body bytes, the failure remains a harness/server transport investigation and must not be converted into a speculative production checkout workaround.
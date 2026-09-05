# ADR-0007: Stale-safe browser mutation transport

- Status: Accepted
- Date: 2026-09-05

## Context

The server already exposes guarded payment/agreement mutation endpoints with cart/customer binding, CSRF validation, per-cart serialization and a server-authoritative `stateVersion`. A one-page browser can still become inconsistent if rapid changes produce overlapping requests and an older response overwrites newer checkout HTML. The browser must also never become the source of truth for payment eligibility, legal requirements or monetary state.

The version-specific checkout shell is not implemented yet, so introducing the mutation transport must not accidentally activate the unfinished checkout flow.

## Decision

1. `views/js/checkout-mutation-client.js` provides the shared browser transport for currently implemented payment/agreement mutations.
2. The client is fail-closed and dormant unless it finds a module-owned `[data-jzopc-checkout]` root containing a complete server-generated bootstrap: positive cart ID, non-empty CSRF token, non-empty authoritative state version, and explicit payment/agreement endpoint URLs.
3. Every request is `POST`, same-origin, form-encoded, and carries only the CSRF token, cart binding, prior state version and operation-specific identifiers. Browser prices/totals or authoritative checkout selections are never submitted.
4. Mutations use latest-intent-wins semantics. Starting a newer mutation increments a local sequence and aborts the prior request when `AbortController` is available. Every response is also checked against the latest sequence, so abort support is not the only race guard.
5. A server `stale_state` response with a fresh state version may update the local version and retry the same latest intent exactly once. The client does not loop indefinitely and does not treat other retryable errors as permission to replay state changes automatically.
6. Before any DOM replacement, the client validates the complete returned section map: every section name must be safe, a corresponding current section must exist, returned HTML must contain exactly one section root, and its `data-jzopc-section` must match the response key. If any section fails validation, none of the returned sections is applied.
7. After successful prevalidation, the client advances the root state version, replaces the full response section set, and emits `jzopc:section:updated` for each replacement. Existing re-entrant payment behavior therefore remounts through the documented lifecycle rather than through duplicated special-case calls.
8. A structured validation failure may still replace server-authoritative sections and advance the server-provided state version before publishing `jzopc:checkout:validation-failed`. Network/malformed-response failures publish `jzopc:checkout:error` and do not apply unverified DOM state.
9. The client does not perform payment form submission or final order handoff. Those remain separate Phase 5 boundaries with fresh server validation and idempotency requirements.
10. Adding this client does not open `INTEGRATION_SHELL_READY` or enable checkout takeover. The future PrestaShop 9.0/9.1 legacy adapter and 9.2+ provider integration must own checkout shell rendering, asset registration and trusted bootstrap generation before this client becomes reachable in a real checkout.

## Security consequences

- a slower superseded response cannot overwrite a newer request merely because it finishes later;
- stale recovery uses only the server-provided current version and is bounded to one replay of the latest user intent;
- a partial/malformed section response cannot produce a partially updated checkout DOM;
- browser input remains limited to identifiers/intent while eligibility, totals and canonical selections stay server-authoritative;
- endpoint URLs/token/state bootstrap must be rendered only by the future active checkout integration after the existing activation policy passes.

## Testing

`CheckoutMutationJavascriptContractSmokeTest` covers the bootstrap requirements, request bindings, payment/agreement payload contract, AbortController/sequence guards, bounded stale-state recovery, section prevalidation lifecycle and error/validation events. GitHub Actions syntax-checks all JavaScript with Node.js 22.

A real browser/PrestaShop integration test is still required to prove rapid mutation ordering, DOM replacement and payment-module reinitialization against the final version-specific checkout shell.

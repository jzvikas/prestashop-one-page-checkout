# ADR-0042: Seed active HTTP runtime carts through Core AJAX cart mutation

## Status

Accepted for the disposable installed-runtime harness. Production checkout readiness remains closed.

## Context

Runtime run `34032227691` on `7eac89f98f2a4461f2b6b1ad05809e006d29483f` produced two important pieces of executed evidence on PrestaShop 9.1.5:

- the fully orderable two-tab finalization reservation Chromium contract passed, including guest identity, Core address, Core carrier, official `ps_checkpayment`, one reservation winner, one `finalization_in_progress` loser, idempotent replay and exact release;
- the later active HTTP fallback contract failed before injecting any OPC failure because its fresh cURL session did not render the healthy OPC root.

The same fallback stage also failed on PrestaShop 9.0.3. The earlier Chromium contracts on those runtime families already create their fresh carts through Core `CartController` with `ajax=1`, while `ActiveCheckoutFallbackHttpContract.php` used a non-AJAX cart-add request and only checked its HTTP status before navigating to `/order`.

That made the fallback contract depend on theme/controller HTML redirect behavior that is not part of the safety property being tested. Its real purpose is to start from a known Core cart and then prove `healthy OPC -> injected integration failure -> native Core fallback -> same-cart recovery`.

## Decision

The active HTTP fallback harness will seed its fresh cart through the same Core AJAX cart-add surface used by the executed Chromium contracts:

- `controller=cart` remains Core-owned through `/cart`;
- `add=1`, `ajax=1`, the fixture product ID and quantity are sent through the normal Front Office request;
- the same cURL cookie jar is retained for the following `/order` requests;
- no cart row, customer, address, carrier, payment selection or order is written directly by the OPC test harness.

The contract continues to require a real healthy OPC root before any failure marker/schema failure is introduced. Therefore this change does not weaken the fallback assertion; it removes an unrelated dependency on non-AJAX cart-page redirect/render behavior.

A source smoke assertion locks `ajax=1` into this disposable runtime contract so it cannot silently drift away from the browser-proven Core mutation surface.

## Consequences

The active fallback runtime test is more deterministic across Hummingbird and supported PrestaShop 9.x families while preserving Core cart/session ownership. The production module, payment handoff, carrier selection and order creation paths are unchanged.

The HTTP fallback contract still destroys/restores only module-owned failure-test state, then disables the temporary fixture and removes its disposable product during cleanup. It never calls `PaymentModule::validateOrder()` and never creates an order.

`INTEGRATION_SHELL_READY` remains `false` in repository production source.

## Verification

The source/runtime and smoke changes are committed. Their CI/runtime jobs triggered after the commit; they must not be described as passing until those exact runs finish successfully. The already executed `34032227691` 9.1.5 job is valid evidence for the orderable concurrent-tab reservation milestone, but its fallback stage is the failing evidence that motivated this ADR rather than proof of this fix.
# ADR-0027: Controlled active HTTP checkout fallback fixture

## Status

Accepted for test infrastructure. Production `INTEGRATION_SHELL_READY=false` remains unchanged. The active path exists only in a disposable `/tmp` copy created by the runtime workflow.

## Context

The production readiness gate correctly prevents custom checkout takeover, but that also means a normal installed runtime cannot prove the real request path when takeover is active. Changing the production source gate, adding a debug endpoint or introducing a configuration bypass would invalidate the safety boundary being tested.

A controlled runtime-only fixture is therefore required to exercise healthy OPC takeover, injected integration failure, Core native fallback and recovery without altering repository production source.

## Decision

1. `tests/Runtime/build-active-checkout-fixture.sh` refuses to run unless `JZOPC_RUNTIME_ACTIVE_FIXTURE=1`.
2. Fixture output is restricted to `/tmp/jzopc-active-fixture*`.
3. The builder copies the repository without `.git`, verifies the source contains exactly one closed readiness constant and patches only the temporary copy to `INTEGRATION_SHELL_READY=true`.
4. The source module and production runtime classes are rechecked after fixture creation to prove no readiness or failure-instrumentation change escaped the disposable copy.
5. `InstrumentActiveCheckoutFailureFixture.php` is also env-guarded and `/tmp`-restricted. It refuses symlink targets and ambiguous patch anchors.
6. Test-only marker checks are injected only into the temporary copies of `CheckoutShellRenderer`, `PrestaShopCheckoutTemplateRenderer` and `CheckoutFrontendAssetRegistrar`.
7. Production source must remain free of `.jzopc-runtime-failure-*` markers.
8. `PrepareActiveCheckoutHttpFixture.php` verifies it is running from the temporary active module, disables native `ps_onepagecheckout` through normal module state where necessary, enables the OPC feature only for the runtime shop, and creates a normal Core product/stock fixture.
9. The HTTP contract creates its cart through the real `/cart` controller and one cookie jar, then requires healthy OPC, Core-native fallback and same-session healthy recovery for:
   - real module selection-table persistence failure;
   - injected shell-service failure;
   - injected real Smarty missing-template failure;
   - injected frontend-asset registration failure.
10. Every marker/schema/config/product mutation has cleanup in `finally` paths.
11. The fixture never calls `validateOrder()`, never sends finalization begin/release, and never inserts cart/order rows directly.
12. The normal closed-readiness HTTP contract runs before the temporary active fixture is created.

## Security rationale

The temporary readiness opening is test data, not a production control plane. Multiple independent path/source checks make accidental mutation of the checkout source tree fail closed. The active contract exercises only takeover/fallback and recoverable identity validation; it intentionally does not place an order.

## Verification

`CheckoutActiveRuntimeFixtureIsolationContractSmokeTest.php` and `CheckoutActiveHttpFallbackRuntimeContractSmokeTest.php` lock the isolation and request-path contracts. The installed runtime workflow must execute them indirectly through the active fixture on all supported runtime families.

A real Chromium contract remains a separate gate in ADR-0028.

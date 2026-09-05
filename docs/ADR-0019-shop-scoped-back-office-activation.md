# ADR-0019: Shop-scoped Back Office checkout activation

## Status

Accepted. Production checkout takeover remains blocked by `INTEGRATION_SHELL_READY=false` until the deferred installed-runtime/browser verification is complete.

## Context

`JZOPC_CHECKOUT_ENABLED` already exists as the merchant-facing feature flag and participates in the same activation policy used by checkout hooks and mutation controllers. Until now the repository had no Back Office configuration surface for that value.

A checkout activation control is unusually sensitive in multistore. A write performed while the Back Office is in all-shops or shop-group context can unintentionally change rollout state for stores the merchant did not mean to touch. The UI also must not allow a merchant toggle to bypass runtime capability checks, the native `ps_onepagecheckout` conflict policy, or the internal readiness gate.

## Decision

1. The standard module `getContent()` entry point delegates to `BackOffice\CheckoutActivationConfigurationPage`; the main module class remains only the integration/bootstrap boundary.
2. The page uses PrestaShop `HelperForm` and the normal `AdminModules` token/link contract.
3. Configuration mutation is allowed only when `Shop::getContext()` is exactly `Shop::CONTEXT_SHOP` and both the concrete shop and shop-group IDs are positive. Group/all-shop contexts are read-only and receive a warning asking the merchant to select one shop.
4. The submitted activation value accepts only the exact scalar values `0` and `1`.
5. A disable request is always allowed for the selected shop.
6. An enable request is re-evaluated server-side through the existing `CheckoutCapabilityDetector` and `CheckoutActivationPolicy` with `featureEnabled=true` and the exact production `INTEGRATION_SHELL_READY` value. Unsupported runtimes, an enabled native provider conflict, or a closed readiness gate reject the write.
7. Successful writes call `Configuration::updateValue()` with explicit shop-group and shop IDs, so the setting is intentionally shop-scoped rather than dependent on an ambiguous Back Office context.
8. The page displays the detected integration path and clear translated warnings for unsupported runtime, native-provider conflict, and readiness lock.
9. `INTEGRATION_SHELL_READY` remains `false`; the configuration page is not a hidden activation bypass.
10. No module version bump or upgrade script is introduced. The configuration key already exists, and this milestone adds only a safe UI around the existing value.

## Consequences

- rollout can be controlled per store once the readiness gate is legitimately opened;
- a merchant cannot accidentally enable all shops from a group/all-shop context;
- hand-crafted POST data cannot bypass the shared activation policy;
- enabling cannot be pre-staged while the internal safety gate is closed, preventing a future code upgrade from silently activating a previously stored `true` value;
- module disable/uninstall behavior and native checkout fallback remain unchanged.

## Verification

`CheckoutBackOfficeActivationContractSmokeTest.php` records the source contract for single-shop scoping, strict boolean parsing, shared-policy/readiness enforcement, explicit configuration scope, `HelperForm`/`AdminModules` integration, unchanged module version and the still-closed production gate.

The new smoke/PHP checks have not yet been executed in GitHub Actions because the repository Actions quota is exhausted. Back Office browser interaction must also be included in the later installed/browser verification matrix before production readiness is claimed.

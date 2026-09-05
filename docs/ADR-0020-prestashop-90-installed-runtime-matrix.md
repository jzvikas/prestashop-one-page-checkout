# ADR-0020: PrestaShop 9.0 installed-runtime matrix coverage

## Status

Accepted for implementation. The new runtime job is intentionally not executed while the repository GitHub Actions quota remains exhausted. `INTEGRATION_SHELL_READY=false` is unchanged.

## Context

The module declares support for all PrestaShop 9.x releases and deliberately uses the legacy `actionCheckoutRender` integration path on PrestaShop 9.0/9.1. The installed-runtime workflow previously covered 9.1.5 and a 9.2-era provider runtime only, leaving the 9.0 branch unproven.

During this review another deterministic runtime-test drift was found: `InstalledModuleContract.php` still required the obsolete module version `0.3.0` even though the current module schema baseline is `0.4.0`. The next installed-runtime execution would therefore fail before providing useful checkout compatibility evidence.

## Decision

1. Add PrestaShop `9.0.3` to the MariaDB-backed installed-runtime matrix as family `9.0` with no native OPC fixture.
2. Treat both 9.0 and 9.1 as the legacy checkout-render family in runtime contracts; only 9.2 exercises the provider interface path.
3. Require every runtime contract to explicitly accept the supported `9.0`, `9.1`, `9.2` family set instead of silently treating an unknown family as the provider branch.
4. Remove the obsolete exact `0.3.0` installed-version assertion. The installed contract now requires at least `0.4.0`, the schema baseline that includes finalization reservation storage.
5. Extend the installed contract to verify `actionValidateOrderAfter`, because successful-order cleanup is part of the current finalization safety lifecycle.
6. Add `CheckoutRuntimeMatrixContractSmokeTest.php` to lock the workflow matrix/family contract and prevent future module-version drift from silently invalidating runtime CI.
7. Do not trigger the workflow while Actions quota is exhausted. Source changes are committed as unexecuted test coverage, not as verified PrestaShop 9.0 compatibility.

## Consequences

- the next permitted runtime execution will exercise the actual declared 9.0 legacy integration path;
- 9.0 failures will be isolated from 9.1/9.2 by the existing non-fail-fast matrix;
- runtime tests no longer fail deterministically because of the stale 0.3.0 assertion;
- the installed test now checks the post-order cleanup hook expected by the 0.4.0 finalization lifecycle;
- production readiness still requires the matrix to execute successfully plus the existing controlled browser/payment/carrier gates.

## Verification state

Static/source review only in this change. The PHP smoke/runtime files and GitHub workflow were not executed in this environment, and no CI result is claimed. Once Actions quota is available, the complete runtime matrix must run and every failure must be fixed before PrestaShop 9.0 support is marked verified.

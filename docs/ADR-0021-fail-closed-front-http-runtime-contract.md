# ADR-0021: Fail-closed Front Office HTTP runtime contract

## Status

Accepted for implementation. The contract is committed but not executed while the repository GitHub Actions quota is exhausted. `INTEGRATION_SHELL_READY` remains `false`.

## Context

The installed-runtime workflow already checks module installation, version capability selection, checkout process adapters, Smarty shell construction and legacy module-front service resolution. Those checks execute inside PHP and do not prove the actual Front Office HTTP boundary.

The readiness gate is a production safety control. While it is closed, two externally observable properties must remain true:

1. normal Core checkout navigation must not render the custom OPC root or register its checkout assets;
2. direct HTTP requests to mutation endpoints must not bypass activation and reach checkout mutation/finalization logic.

A source-only assertion is insufficient for this boundary because routing, module front-controller dispatch and hook/media registration are runtime behaviors.

## Decision

Add `tests/Runtime/FailClosedHttpContract.php` and execute it in every PrestaShop runtime matrix family after the module and any native-OPC conflict fixture are installed.

The workflow starts a loopback PHP Front Office server only for the test job. The contract then verifies:

- `/order` remains reachable through Core while the module readiness gate is closed;
- the response does not contain `[data-jzopc-checkout]`;
- the response does not contain module checkout JS/CSS asset URLs;
- a direct POST to `/module/jzonepagecheckout/finalize` returns HTTP 404 with stable `checkout_unavailable` JSON;
- submitted fake CSRF/state material is not reflected in the rejection response.

The test intentionally supplies invalid binding values. With the activation gate closed, the request must be rejected before CSRF, cart, stale-state or finalization business logic becomes relevant.

## Non-goals

This contract does not:

- enable `INTEGRATION_SHELL_READY`;
- patch or override the production readiness constant;
- create a cart or an order;
- call `PaymentModule::validateOrder()`;
- claim that custom checkout takeover works;
- replace the pending guest/login/address/carrier/payment/free-order/concurrent-tab browser matrix.

## Consequences

- fail-closed behavior is now represented as an executable HTTP contract rather than only PHP policy assertions;
- all supported runtime families share the same external activation-boundary check;
- a routing, media-hook or mutation-controller regression can fail the runtime job before production activation is considered;
- successful execution is still pending and must not be reported as passing evidence until Actions actually runs.

## Verification state

The contract and workflow wiring were source-reviewed in this change. They were not executed because GitHub Actions quota remains exhausted. The first future runtime execution must fix any server/router/version-specific issue before this milestone is considered verified.

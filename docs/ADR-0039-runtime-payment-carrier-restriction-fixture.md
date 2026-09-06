# ADR-0039: Runtime payment carrier restriction fixture

## Status

Accepted for the disposable PrestaShop 9.1.5 runtime harness. Production checkout readiness remains closed.

## Context

The installed PrestaShop 9.1.5 orderable concurrent-tab browser contract progressed through real guest identity, Core address persistence and Core carrier discovery/selection, but then found zero `ps_checkpayment` payment options.

The production OPC payment presenter was already delegating discovery to PrestaShop `PaymentOptionsFinder`, so fabricating or injecting a payment option would have weakened the compatibility boundary and hidden the actual Core filtering decision.

The runtime matrix deliberately installs PrestaShop with `--fixtures=0`. It installs the pinned official `ps_checkpayment` module before `PrepareActiveCheckoutHttpFixture.php` creates the deterministic `JZ OPC Runtime Carrier`. PrestaShop `PaymentModule::install()` snapshots existing carrier restrictions into `module_carrier` by carrier reference. A carrier created later is therefore not automatically allowed for that already-installed payment module, and Core correctly filters the module from `paymentOptions` for the browser-created cart.

## Decision

Keep all production OPC payment discovery and native payment-module mechanics unchanged.

For the disposable PrestaShop 9.1 runtime fixture only, after the deterministic carrier is created through Core and associated with its shop/zones/groups, add that carrier's `id_reference` to the installed official `ps_checkpayment` module's normal Core `module_carrier` restriction table for the runtime shop. Use `INSERT_IGNORE` so fixture preparation remains deterministic and idempotent.

Before any Chromium checkout gate starts, `ActiveCoreCarrierAvailabilityContract.php` must additionally prove that:

- `ps_checkpayment` is installed and enabled;
- the deterministic carrier exposes a positive Core carrier reference;
- exactly one current-shop `module_carrier` association exists for the payment module and that carrier reference;
- the existing Core carrier zone/group/shop/product discovery gates still pass.

The runtime contract must not submit a payment form, call `validateOrder()`, fabricate a payment option, persist a browser delivery selection or create an order.

## Consequences

This keeps the test environment faithful to PrestaShop's own payment-module restriction model while correcting the artificial installation-order gap introduced by the `--fixtures=0` harness. The browser must still receive `ps_checkpayment` through Core `PaymentOptionsFinder` and the normal `paymentOptions` hook.

No production payment/carrier restrictions are modified. No OPC code path owns order creation. The official payment module remains responsible for its native validation/order flow when later completion gates intentionally exercise it.

`INTEGRATION_SHELL_READY` remains `false` in repository production source.

## Verification

The source/runtime contracts for the fixture association are committed, but their current-HEAD installed-runtime result must be treated as unverified until GitHub Actions executes the relevant PrestaShop 9.1.5 gate. Earlier runtime failure is evidence only for the diagnosed boundary: it reached the payment-selection step with valid live Core delivery state but zero `ps_checkpayment` options.

A future green orderable browser gate may expose the next final-submit/payment milestone; it does not by itself prove native payment completion, successful Core-order cleanup, duplicate refresh safety or TTL recovery.
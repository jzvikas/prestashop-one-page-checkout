# ADR-0040: Standalone runtime fixture fail-fast cleanup

## Status

Accepted for the disposable installed-runtime harness. Production checkout readiness remains closed.

## Context

The PrestaShop 9.1.5 runtime run on `a1718ce90be3d938f8085dc96064d2a4b666d0b5` failed while preparing the active checkout fixture. The failure was not caused by OPC carrier discovery or payment presentation: the fixture's error branches called `Carrier::delete()` from a standalone PHP process bootstrapped through `config/config.inc.php` without a Symfony Front Kernel container.

In PrestaShop 9.1.5, `Carrier::delete()` calls `Carrier::isUsed()`, which resolves the Symfony container through `ContainerFinder`. Without a booted kernel, that cleanup path throws `ContainerNotFoundException` and masks the original fixture failure. The runtime database is disposable and the fixture exits immediately on any preparation error, so model cleanup on the failing process does not protect production data and can reduce diagnostic fidelity.

## Decision

Keep carrier creation and carrier eligibility setup on normal PrestaShop/Core paths, but do not invoke `Carrier::delete()` from `PrepareActiveCheckoutHttpFixture.php` failure branches.

The active fixture must:

- continue creating the deterministic carrier with the Core `Carrier` model;
- continue associating it with Core zones, customer groups, the runtime shop and the shop default-carrier configuration;
- continue using Core `module_carrier` restrictions for the pinned official `ps_checkpayment` fixture;
- fail immediately with the first meaningful fixture error instead of attempting a container-dependent carrier deletion;
- rely on destruction of the disposable runtime database/job environment for failure cleanup.

A source smoke contract locks the absence of `Carrier::delete()` from this standalone fixture and the explanatory fail-fast boundary.

## Consequences

The runtime harness no longer converts an underlying carrier/payment fixture error into a misleading Symfony-container fatal. This improves the reliability of the installed-runtime signal without changing production OPC behavior, carrier selection, payment discovery or order creation.

This decision is deliberately limited to disposable test infrastructure. It is not a recommendation to bypass PrestaShop lifecycle methods in production module code.

`INTEGRATION_SHELL_READY` remains `false` in repository production source.

## Verification

The failing 9.1.5 GitHub Actions runtime on `a1718ce90be3d938f8085dc96064d2a4b666d0b5` is executed evidence for the `Carrier::delete()` / missing-container failure mode. The fail-fast fixture change and its smoke assertion are committed, but the corrected 9.1.5 runtime must not be called passing until GitHub Actions executes the new HEAD successfully.

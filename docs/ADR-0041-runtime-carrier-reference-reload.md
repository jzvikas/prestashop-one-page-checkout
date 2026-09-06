# ADR-0041: Reload the runtime carrier before using its persisted reference

## Status

Accepted for the disposable installed-runtime harness. Production checkout readiness remains closed.

## Context

After ADR-0040 removed the container-dependent `Carrier::delete()` cleanup that had masked the real PrestaShop 9.1.5 fixture error, the executed 9.1.5 runtime exposed the underlying failure:

`Runtime carrier does not expose a positive Core carrier reference for payment restrictions.`

PrestaShop 9.1.5 `Carrier::add()` first persists the ObjectModel and then executes a separate SQL update setting `id_reference = id_carrier`. That SQL update does not mutate the already-instantiated PHP object's `id_reference` property. Consequently, reading `$carrier->id_reference` immediately after `add()` observes stale in-memory state even though the database already contains the authoritative positive reference.

The official payment-module carrier restriction uses the carrier reference, not a synthetic OPC identifier, so the harness must read the value exactly as Core persisted it.

## Decision

Immediately after successful `Carrier::add()`, reconstruct the carrier using the Core model and the generated carrier ID. Require the reloaded object to be valid and to expose a positive `id_reference` before using it for payment restriction checks.

The fixture must not derive or assign the reference itself. It must not assume `id_reference === id_carrier` in its own code even though that is how current Core persists a newly created carrier. The database-backed Core model remains authoritative.

A source smoke contract requires this reload boundary before the 9.1 payment restriction fixture is accepted.

## Consequences

The 9.1 runtime fixture now observes the persisted Core carrier reference rather than stale PHP object state. No production OPC carrier/payment logic changes, and no delivery option or payment option is synthesized.

This also keeps the fixture resilient if Core changes how the reference is populated while preserving the model's public persisted contract.

`INTEGRATION_SHELL_READY` remains `false` in repository production source.

## Verification

The PrestaShop 9.1.5 runtime executed on `9f94ad2ac27a21e20afa5fda3e3111bd4a7ec913` is evidence for the stale in-memory reference failure. The carrier reload change and its smoke contract are committed, but the corrected runtime is not considered passing until GitHub Actions executes the new HEAD successfully.

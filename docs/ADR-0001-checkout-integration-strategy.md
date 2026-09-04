# ADR-0001: Version-aware checkout integration

- Status: Accepted
- Date: 2026-09-04

## Context

The module targets all PrestaShop 9.x releases while the official checkout extension architecture changes at 9.2. PrestaShop 9.2 introduces `actionCheckoutBuildProcess` and `CheckoutProcessProviderInterface`; earlier 9.x releases do not have that provider contract. PrestaShop 9.2 also ships the native `ps_onepagecheckout` module, so blindly enabling another provider can create a conflict.

## Decision

Use a capability-driven integration boundary.

1. On PrestaShop 9.2+, use `actionCheckoutBuildProcess` only when both the hook and `CheckoutProcessProviderInterface` are available.
2. Keep our provider inactive when native `ps_onepagecheckout` is enabled.
3. On PrestaShop 9.0/9.1, integrate through the existing `actionCheckoutRender` lifecycle using a dedicated legacy adapter.
4. Do not reference 9.2-only classes from code paths that must load on 9.0/9.1.
5. Do not introduce a Core/controller override unless a later source-backed compatibility test proves a required behavior cannot be implemented safely through hooks/adapters.
6. Keep the module inert if required runtime capabilities are missing. Native checkout must remain available rather than risking a broken order flow.

## Rationale

This preserves the safest fallback on every supported version, minimizes Core coupling, avoids two active provider implementations on 9.2+, and gives the application layer a stable integration contract independent of the PrestaShop minor version.

## Consequences

- checkout builders and state synchronization live outside the main module class;
- provider-specific classes must be loaded only on compatible 9.2+ runtimes;
- legacy and provider integration paths need separate integration/functional tests;
- conflict detection is a release-blocking requirement before enabling checkout takeover;
- disabling/uninstalling the module must leave Core/native checkout untouched.

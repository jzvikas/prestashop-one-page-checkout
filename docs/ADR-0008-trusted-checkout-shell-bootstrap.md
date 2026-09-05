# ADR-0008: Trusted checkout shell bootstrap

## Status

Accepted.

## Context

The browser mutation transport and payment interaction controller are implemented, but they intentionally remain dormant until a module-owned checkout shell supplies a trusted bootstrap. The integration readiness gate is still closed, so the payment/agreement mutation endpoints also remain unavailable to normal checkout traffic.

The checkout shell must not invent a second client-owned state model. Its initial cart identifier, state version, persisted payment/agreement selections and rendered sections must all come from the same server-authoritative state used by mutation guards and renderers.

PrestaShop 9.2+ exposes `PrestaShop\PrestaShop\Adapter\Order\Checkout\CheckoutProcessProviderInterface` through `actionCheckoutBuildProcess`. The current Core contract requires `isEnabled(): bool` and `buildCheckoutProcess(CheckoutSession, TranslatorComponent): CheckoutProcess`. The native `ps_onepagecheckout` module uses this provider mechanism. PrestaShop 9.0/9.1 do not expose that provider path and continue to require the guarded `actionCheckoutRender` integration strategy described by ADR-0001.

## Decision

A module-owned shell/bootstrap foundation is implemented before either version-specific checkout takeover path is activated.

`CheckoutShellRenderer`:

- loads `CheckoutServerSelections` once for the active PrestaShop context;
- passes the same selections into both bootstrap state construction and section rendering;
- renders only currently implemented sections: addresses, delivery, payment, agreements and summary;
- deliberately does not fabricate an identity section;
- renders through `PrestaShopCheckoutTemplateRenderer` and the module-owned `checkout-shell.tpl` template.

`CheckoutBrowserBootstrapFactory`:

- requires an already-loaded positive cart;
- generates the CSRF token through `Tools::getToken(false)`, matching the server validator contract;
- builds the state version through `PrestaShopCheckoutStateFactory` plus `CheckoutStateVersioner` using the same persisted server selections;
- generates HTTPS-capable module URLs through PrestaShop `Link::getModuleLink()` for `paymentselection` and `agreements`;
- fails closed when cart, token, Link or endpoint generation is unavailable.

The shell exposes only the bootstrap values required by `views/js/checkout-mutation-client.js`: cart ID, CSRF token, state version and the two mutation endpoint URLs. All attribute values are escaped by Smarty. Section fragments are emitted as trusted server-rendered HTML; this does not widen the raw-HTML trust boundary documented in `SECURITY.md`.

`CheckoutFrontendAssetRegistrar` registers the existing payment and mutation JavaScript controllers through the PrestaShop front-controller `registerJavascript()` API. It also fails closed when that API is unavailable.

## Activation rule

`JzOnePageCheckout::INTEGRATION_SHELL_READY` remains `false` after this decision. The new shell, bootstrap and asset registrar are building blocks, not permission to replace checkout yet. Mutation endpoints therefore remain protected by the existing shared activation gate.

The gate must not be opened merely because the shell template exists. It may be reconsidered only after both version-specific integration paths are implemented and runtime-tested sufficiently to prove safe fallback and checkout correctness.

## Compatibility and security consequences

- No 9.2-only interface is referenced by module-loadable 9.0/9.1 code in this milestone.
- The native `ps_onepagecheckout` conflict policy remains unchanged.
- Initial payment/agreement checked state and the browser state version are derived from the same persisted selection row, preventing bootstrap drift.
- Client money totals, customer payloads, passwords, payment secrets and payment form data are not added to bootstrap attributes.
- The CSRF token is present in page markup by design for same-origin checkout mutations and must never be logged.
- The shell currently lacks identity/customer capture and therefore is not a complete checkout replacement.

## Next milestone

Implement the real version-specific integration adapters without opening the readiness gate prematurely:

1. PrestaShop 9.0/9.1: a guarded `actionCheckoutRender` adapter that uses the Core checkout session/process lifecycle and can host the module shell without brittle theme overrides.
2. PrestaShop 9.2+: a provider implementation matching the exact `CheckoutProcessProviderInterface` contract and returning a real `CheckoutProcess`, while remaining conflict-safe with native `ps_onepagecheckout`.
3. Wire shell rendering and asset registration only inside the active version-specific path.
4. Add runtime-focused tests for disable/fallback, provider conflict handling, session ownership and shell/bootstrap lifecycle before considering `INTEGRATION_SHELL_READY=true`.

Identity handling, address/carrier mutations and Phase 5 final validation/idempotent payment handoff remain release-blocking work after the integration path is live.
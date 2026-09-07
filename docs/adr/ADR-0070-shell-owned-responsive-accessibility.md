# ADR-0070: Shell-owned responsive and accessibility presentation

- Status: Accepted
- Date: 2026-09-07

## Context

The OPC source already preserves Core forms, payment/carrier hook output and semantic section headings, but the active custom shell had no module-owned stylesheet. Its usable layout therefore depended on accidental theme styling even though Classic, Hummingbird and third-party theme compatibility are explicit release goals.

The stylesheet must not become another early page-asset dependency. PrestaShop 9.0/9.1 can decide the legacy checkout takeover after the page-level asset queue has already been finalized, which is why the required JavaScript runtime is delivered by the successfully rendered shell itself.

## Decision

The successfully rendered OPC shell owns one namespaced stylesheet, `views/css/checkout.css`, through the same `_MODULE_DIR_`-derived, escaped asset boundary as its JavaScript runtime.

The shell separates the mutable checkout sections from a summary/finalization rail without changing any `data-jzopc-section` or payment/carrier form boundary. Desktop uses a two-column layout with a sticky summary/finalization rail; narrow viewports collapse to a single column. Styling is namespaced under `.jzopc-checkout` and does not inject or replace theme/Core compatibility assets.

Accessibility presentation now locks these source-level properties:

- visible `:focus-visible` indication rather than removing browser focus;
- minimum target sizing for module-owned checkout actions;
- responsive layout without fixed checkout widths;
- reduced-motion handling;
- a stable atomic polite status region associated with the module-owned final-submit button;
- no `pointer-events: none` or equivalent CSS shortcut that could silently disable Core/module-owned payment controls;
- native payment forms and hook HTML remain intact and are not rewritten for presentation.

The stylesheet is emitted only when the custom shell itself renders. Native Core fallback and the native `ps_onepagecheckout` conflict path therefore receive neither the OPC stylesheet nor its JavaScript runtime.

## Security and compatibility consequences

This is presentation hardening only. It does not make browser state authoritative, alter CSRF/cart/customer bindings, change mutation locking/stale-state behavior, select a carrier/payment option, release a finalization reservation or create an order.

Third-party payment forms remain an explicit compatibility boundary. The stylesheet styles module-owned option containers but does not hide, disable, reposition with scripting or rewrite third-party successful controls.

`INTEGRATION_SHELL_READY` remains `false`. Responsive/accessibility source hardening removes one release-readiness gap but does not waive the still-open representative payment/carrier, identity/address, multistore and zero-total runtime/browser gates.

## Verification

Source smoke coverage locks the exact shell-owned stylesheet manifest, escaped `_MODULE_DIR_` URL derivation, responsive breakpoints, keyboard focus indication, target sizing, reduced-motion rule, final-submit live-region association and the prohibition on CSS that silently disables interactive payment controls.

Runtime/browser verification remains evidence only when the corresponding GitHub Actions workflow actually completes successfully; this ADR does not convert the known Core free-order PDF stall in the disposable runtime fixture into a green checkout claim.

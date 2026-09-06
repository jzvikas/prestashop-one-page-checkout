# ADR-0035 — Deterministic active Core carrier runtime gate

## Status

Accepted for the installed PrestaShop 9.0/9.1 active-checkout runtime fixture. Production checkout readiness remains closed.

## Context

The executed PrestaShop Runtime run `34021181704` on repository commit `5d33e8b553197b3ec719052ae6ac30804f918455` reached the PrestaShop 9.1.5 fully-orderable concurrent-tab Chromium gate after successfully completing installation, both MariaDB finalization-reservation contracts, Core process/session contracts, fail-closed HTTP checks, active checkout Chromium, finalization preflight and concurrent-tab preflight. The orderable gate then failed before payment selection because the active physical checkout rendered no Core `delivery_option` input.

The runtime installation deliberately uses `--fixtures=0`. A deterministic free carrier is therefore created only inside the disposable active fixture. Earlier fixture hardening associated that carrier with active zones, all customer groups and the runtime shop, but the fixture still relied on default/null `Carrier` ObjectModel values for non-module and package-limit fields and only proved table associations. That was insufficient evidence that Core carrier discovery itself accepted the fixture before the browser exercised address/cart state.

A browser failure with no delivery option can originate at two materially different boundaries:

1. the fixture carrier/product is filtered out by Core carrier discovery; or
2. the browser guest/address/cart transition fails to present otherwise-valid Core delivery state.

Those cases must be distinguished without injecting a delivery option, trusting browser values, or weakening the final-submit gate.

## Decision

1. The disposable runtime carrier now explicitly persists the semantics required for a deterministic Core-owned free carrier: `is_module=false`, no external module, `is_free=true`, `shipping_method=Carrier::SHIPPING_METHOD_FREE`, `need_range=false`, no handling surcharge and zero package dimension/weight limits.
2. Existing Core zone, group, shop and shop-scoped `PS_CARRIER_DEFAULT` associations remain mandatory.
3. Add `tests/Runtime/ActiveCoreCarrierAvailabilityContract.php` for the active 9.0/9.1 runtime families.
4. Run that contract immediately after `PrepareActiveCheckoutHttpFixture.php` and before Playwright installation/Chromium gates.
5. The contract loads the exact runtime product/default carrier and proves, through real PrestaShop APIs, that:
   - the carrier is active, free, non-module and no-range;
   - the default country is active and carrier-zone association exists;
   - the runtime shop and configured guest group associations exist;
   - `Carrier::getCarriersForOrder()` retains the carrier for the default zone and guest group;
   - `Carrier::getAvailableCarrierList()` retains the carrier for the physical fixture product.
6. The probe does not call `Cart::setDeliveryOption()`, does not write a `delivery_option`, does not submit any payment form and does not create or validate an order.
7. The existing Chromium gate remains strict: it must still discover and select a real Core-rendered `input[name="delivery_option"]`. No synthetic DOM option, carrier identifier, price or delivery-option payload is introduced.
8. Add a source smoke contract locking the fixture flags, Core discovery calls, workflow ordering, no-selection-injection rule and `INTEGRATION_SHELL_READY=false` boundary.

## Security and correctness consequences

- The server remains authoritative for carrier availability and selection.
- A browser cannot use this runtime-only probe to forge carrier state because the contract is a CLI test under `tests/Runtime`, not a production endpoint.
- The fixture no longer depends on nullable/default `Carrier` ObjectModel fields for the non-module/no-limit semantics that influence Core carrier filtering.
- A future runtime failure can now identify carrier-fixture rejection before browser execution rather than conflating it with address/cart rendering failure.
- Carrier selection continues to use the production guarded mutation path, fresh Core delivery options, CSRF/cart/customer binding, cart mutex and stale-state protection.
- Payment-module/Core order ownership is unchanged.

## Verification

The source changes and smoke contract are committed as a normal quality gate. The previous executed runtime evidence is the failed run `34021181704`; it is not evidence that this new carrier gate passes. The new runtime and Chromium gates must be executed on the new commit before this milestone is treated as runtime-verified.

## Remaining work

If the new Core carrier availability contract passes but the 9.1.5 Chromium orderable gate still renders no delivery option, the next investigation boundary is explicitly the browser-created delivery address/cart state: persisted `Cart::id_address_delivery`, address country/zone, customer groups and `Cart::getDeliveryOptionList()` after the guarded address mutation. If the Core carrier contract fails, the failing Core discovery layer becomes the fixture issue to fix first.

Even after a green orderable contention gate, native payment completion, successful Core-order reservation cleanup, payment failure/abandonment/TTL recovery, broader carrier/payment-module compatibility and other release gates remain unfinished. `INTEGRATION_SHELL_READY` stays `false`.
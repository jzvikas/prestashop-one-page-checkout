# ADR-0034: Required OPC and late-takeover compatibility assets are owned by the custom checkout shell

## Status

Provisional. Shell-owned delivery of the six OPC safety scripts is proven far enough to expose the next browser dependency. A theme-aware shell-level Core-jQuery compatibility boundary is now implemented. Static CI is green for that implementation; the installed PrestaShop 9.0/9.1 Chromium matrix is the required runtime proof before this boundary is considered verified.

## Context

The installed PrestaShop 9 browser matrix exposed a lifecycle mismatch that source-only contracts did not reveal. On the legacy 9.0/9.1 path the custom OPC shell could be selected after the page-level asset lists had already been prepared. A checkout shell without its browser safety runtime is unacceptable because mutation serialization, stale-state handling, payment handoff and final-submit guarding would be absent.

The runtime investigation established the failure in layers:

1. Re-registering the six keyed OPC assets from `actionCheckoutRender` was too late; the custom shell rendered with no OPC script tags.
2. Identifying the order controller by stable `php_self === 'order'` rather than one concrete controller alias did not change that lifecycle fact. Runtime run `34015527664` on commit `352978569ca74fa60eee57127c2cd43e4e12f408` again reached the 9.1.5 active Chromium gate with valid cart/CSRF/state/bootstrap data but no OPC runtime scripts.
3. Moving the six OPC files into the shell produced their expected script tags. Runtime run `34016579028` on commit `f201126f913bcd7cc5c573bba828c441c596943e` then exposed a CI PHP-router defect: existing module JavaScript files were routed through Front Office and returned application 404 instead of being served statically. `tests/Runtime/prestashop-http-router.php` now preserves normal static-file semantics for safe existing GET/HEAD paths.
4. After the static-router correction, runtime on HEAD `7b8a6511ba8c7c93fe302a50516a5e6538b134db` served all six OPC files but Chromium failed with `jQuery is not defined` on both the 9.0/9.1 active path. The 9.1.5 job had already passed installation, sequential/process-concurrent MariaDB reservation, Core process adapter, integration isolation, Smarty shell, session, fail-closed HTTP and active fixture preparation. The 9.2 native-OPC conflict job remained green.
5. Replacing the deprecated `FrontController::addJquery()` helper with `Media::getJqueryPath()` plus modern `FrontController::registerJavascript()` was not sufficient. Runtime run `34018103684` on code HEAD `6acbe852661ae65d40c7d2202b98de9a112315d3` again reached the active 9.0.3/9.1.5 Chromium gate and failed with the same `jQuery is not defined`; 9.2.0-beta.1 conflict isolation succeeded.

The fifth result is decisive. PrestaShop's modern `JavascriptManager` is the correct Core page-level registration system, but an active legacy OPC takeover may be decided after the theme's JavaScript data has already been materialized for rendering. An idempotent late `registerJavascript()` call therefore cannot be the sole delivery authority for a dependency required by the newly selected custom checkout shell.

PrestaShop Core also provides the correct duplicate-avoidance capability: the active theme exposes `requiresCoreScripts()`. Themes that require Core scripts already receive Core's compatibility bundle and must not receive a second jQuery instance. Hummingbird can declare that Core scripts are not required, while third-party Core-rendered checkout fragments can still depend on the Core-owned jQuery API.

## Decision

The rendered custom checkout shell is the authoritative delivery boundary for assets whose necessity becomes known only when that shell is selected.

`CheckoutFrontendAssetRegistrar` owns two shell manifests:

- `shellJavascriptUrls()` contains exactly the six OPC safety/runtime files and derives their URLs from PrestaShop `_MODULE_DIR_`;
- `shellCompatibilityJavascriptUrls(Context)` is theme-aware. If the active theme's `requiresCoreScripts()` returns true, it returns an empty list so Core's existing compatibility stack remains the only copy. If Core scripts are not required, it returns only the jQuery file resolved by PrestaShop `Media::getJqueryPath()`.

`CheckoutShellRenderer` resolves both manifests before rendering. `checkout-shell.tpl` emits Core compatibility files synchronously before any trusted Core/theme/module checkout section fragment is parsed, because those fragments can contain inline/native integration code that expects jQuery at parse time. The six OPC safety files remain escaped, same-origin, deferred external scripts after the shell markup.

The early compatibility hook still registers Core jQuery through `FrontController::registerJavascript()` with stable asset ID `jzopc-core-jquery`, head position and priority 0. That remains useful when activation is known early enough, but it is no longer treated as authoritative for a late legacy takeover. The shell manifest closes that lifecycle gap.

The module never vendors, copies, modifies or impersonates jQuery. It resolves the exact Core-owned path. It also never injects a second jQuery instance into themes that declare Core scripts are already required.

The six OPC files are never registered through the page-level JavaScript manager. This preserves one execution authority for them and avoids duplicated mutation/payment/final-submit handlers.

If the theme capability, Core jQuery resolver, page-level registration boundary or either shell manifest cannot be validated, shell preparation/takeover fails closed and Core checkout remains authoritative.

No payment-form ownership, carrier mechanics, server mutation authority, reservation semantics or Core order creation path changes. The OPC module still never calls `PaymentModule::validateOrder()` or creates orders directly. `INTEGRATION_SHELL_READY` remains `false` in repository production source.

## Verification

Source/smoke contracts now lock all of the following:

- all six OPC runtime files remain in the shell-owned manifest;
- runtime URLs derive from PrestaShop's module URI and are escaped;
- Core jQuery derives from `Media::getJqueryPath()`;
- the early page-level path uses modern `FrontController::registerJavascript()` rather than deprecated `addJquery()`;
- shell compatibility selection uses the active theme's `requiresCoreScripts()` contract;
- Core-script themes receive no shell-level jQuery duplicate;
- non-Core-script themes receive only the Core-resolved jQuery URL;
- compatibility assets are emitted synchronously before trusted checkout section fragments;
- OPC safety scripts remain deferred and shell-owned;
- provider/legacy takeover validates asset compatibility before exposing the custom process;
- production readiness remains closed and no takeover hook calls `validateOrder()`.

`tests/Browser/active-checkout-browser-contract.mjs` remains the runtime authority. It rejects browser page errors, requires successful responses for every OPC safety script, requires `window.JzOpcMutationClient`, requires the `jzopc:checkout:initialized` lifecycle event and therefore still fails naturally if jQuery-dependent Core/module checkout integration executes without jQuery. The prior `jQuery is not defined` failure has not been suppressed or reclassified.

Static CI run `34018435831` on implementation/test HEAD `b0d4faa8777cca02621a4a780958206d2617cdfe` completed successfully through Composer metadata, PHP syntax, JavaScript syntax and the full smoke suite.

Installed PrestaShop Runtime run `34018435959` on the same code/test HEAD is the required browser/runtime proof for the new theme-aware shell compatibility boundary. Until its 9.0/9.1 Chromium jobs complete successfully, the change is implemented but not runtime-verified.

## Invariants

- no custom shell may be considered production-ready unless every required OPC runtime and Core compatibility dependency is actually delivered and initializes without browser errors;
- native/conflicting checkout fallback remains free of OPC runtime assets;
- a Core-script theme must not receive a second jQuery instance from OPC;
- a non-Core-script theme may receive only PrestaShop Core's own resolved jQuery path, never a module-vendored replacement;
- browser state remains non-authoritative; server cart/customer/CSRF/state-version validation and cart mutex semantics remain unchanged;
- third-party payment/carrier forms remain Core/module-owned and are not recreated by OPC;
- the OPC module never creates orders directly and never calls `PaymentModule::validateOrder()`;
- `INTEGRATION_SHELL_READY` remains `false` until this and the remaining final payment-completion gates are genuinely proven.

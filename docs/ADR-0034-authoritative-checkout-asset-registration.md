# ADR-0034: Required OPC assets are owned by the custom checkout shell

## Status

Provisional; shell-owned delivery is implemented and source-contracted. The latest executed PrestaShop 9.0/9.1 Chromium run proved delivery of the six OPC scripts but exposed a separate Core-jQuery compatibility defect on Hummingbird. A modern Core asset-manager registration fix is now implemented and must pass the installed Chromium matrix before this boundary is treated as verified.

## Context

The installed PrestaShop 9 browser matrix exposed a lifecycle mismatch that source-only contracts did not reveal. On the legacy 9.0/9.1 path the custom OPC shell rendered with valid server-generated cart, CSRF, state-version and mutation endpoint bindings, while none of the six required `jzonepagecheckout` JavaScript assets appeared in the document. Without those scripts the browser cannot initialize mutation serialization, stale-state handling, payment handoff or final-submit guards.

Two Core asset-manager hypotheses were executed and disproved:

1. re-registering the keyed assets from `actionCheckoutRender` was too late; the custom shell still rendered with `scriptSources: []`;
2. changing the early `actionFrontControllerSetMedia` controller guard from `instanceof OrderController` to the stable `php_self === 'order'` identity also did not close the defect. PrestaShop Runtime run `34015527664` on commit `352978569ca74fa60eee57127c2cd43e4e12f408` again reached the active 9.1.5 Chromium contract with a valid OPC bootstrap but no OPC script tags or network responses.

The second run is important evidence: installation, installed contracts, sequential MariaDB finalization reservation, process-concurrent MariaDB reservation, Core process adapter, failure isolation, Smarty shell, session, fail-closed HTTP and active fixture preparation all succeeded before Chromium failed solely on required asset delivery. The same run's 9.2.0-beta.1 native-OPC conflict scenario completed successfully and intentionally skipped active OPC browser takeover.

Therefore page-level Core asset registration cannot be the safety boundary for a checkout process that is itself selected later in the request lifecycle.

After moving delivery into the shell, executed PrestaShop Runtime run `34016579028` on commit `f201126f913bcd7cc5c573bba828c441c596943e` proved the next layer of the contract. The 9.1.5 browser received all six expected `<script>` URLs from the OPC shell, but `payment-controller.js` returned HTTP 404 and no mutation lifecycle initialized. Inspection of the runtime harness showed that the PHP development server was started with `/tmp/prestashop/index.php` as an unconditional router, so even an existing `/modules/jzonepagecheckout/views/js/*.js` request was sent through the PrestaShop Front Office controller and converted to an application 404 instead of being served as a static file.

That 404 was a runtime-harness transport defect, not evidence that the production module URL was wrong. The runtime server now mirrors a normal web server through `tests/Runtime/prestashop-http-router.php`: safe GET/HEAD requests for existing files are returned to PHP's built-in static server, while dynamic, missing or traversal-like requests continue through the real PrestaShop `index.php` entry point.

The next executed runtime on HEAD `7b8a6511ba8c7c93fe302a50516a5e6538b134db` reached the active 9.0.3 and 9.1.5 Chromium contract after the static-router fix. The six OPC scripts were served, but Chromium failed with `jQuery is not defined`. The same 9.1.5 job had already passed module installation, sequential MariaDB reservation, process-concurrent MariaDB reservation, Core process adapter, integration failure isolation, Smarty shell, session, fail-closed HTTP and active fixture preparation. The 9.2.0-beta.1 native-OPC conflict job was green.

Source inspection against PrestaShop 9.1.5 explains that failure. `FrontController::addJquery()` is a deprecated compatibility helper which ultimately appends the Core-resolved jQuery path to the legacy `js_files` array. Hummingbird renders its normal JavaScript through the modern `JavascriptManager` pipeline and does not expose a global jQuery itself. Calling `addJquery()` therefore did not guarantee a browser-visible Core jQuery dependency on this checkout path even though the call itself succeeded.

## Decision

The rendered custom checkout shell remains the authoritative delivery boundary for the six required OPC external JavaScript files.

`CheckoutFrontendAssetRegistrar` owns a single manifest of required OPC asset paths and derives their URLs from PrestaShop's module path. `CheckoutShellRenderer` resolves that manifest before rendering the shell and passes it to `checkout-shell.tpl`. The template emits escaped, same-origin, deferred `<script>` elements only after the OPC root has been rendered.

Core jQuery is a separate compatibility dependency, not an OPC-owned runtime asset. The registrar now resolves it through PrestaShop `Media::getJqueryPath()` and registers that Core-owned path through the authoritative `FrontController::registerJavascript()` modern asset manager with a stable `jzopc-core-jquery` ID, head position and earliest priority. Repeated calls from media/provider/legacy takeover boundaries are idempotent by asset ID. The module does not vendor, copy, rewrite or impersonate jQuery.

The deprecated `FrontController::addJquery()` path is no longer used by OPC because it writes only to the legacy queue that failed the real Hummingbird runtime contract. The modern manager is used only for the Core jQuery compatibility dependency. The six OPC safety scripts are intentionally not registered there, preserving the shell as their sole delivery boundary and preventing duplicate execution.

This has four safety properties:

- if shell preparation cannot resolve the required OPC manifest, shell preparation throws before provider exposure / legacy process replacement and the existing request-local fallback keeps Core checkout authoritative;
- if the Core jQuery resolver or modern JavaScript registration boundary is unavailable, takeover fails closed before the custom process is exposed;
- native Core checkout or an incompatible/native OPC conflict never renders the custom shell, so it never receives the OPC JavaScript runtime;
- the custom shell can no longer depend on a Core page-level asset queue whose lifecycle precedes legacy checkout takeover, while Core/third-party forms still receive a Core-owned compatibility dependency through the asset system Hummingbird actually renders.

No payment form ownership, carrier mechanics, mutation authority, reservation semantics or Core order creation path changes. The OPC module still never calls `PaymentModule::validateOrder()` or creates orders directly. `INTEGRATION_SHELL_READY` remains `false` in production source.

## Verification

`tests/Smoke/CheckoutTakeoverAssetRegistrationContractSmokeTest.php` locks all of the following:

- the manifest contains all six required OPC runtime files;
- the manifest resolves from the module path;
- Core jQuery is resolved with `Media::getJqueryPath()`;
- Core jQuery is registered through `FrontController::registerJavascript()` at the compatibility boundary before shell-manifest validation;
- the deprecated `addJquery()` helper is not used;
- the modern manager registration occurs exactly once in the registrar source so OPC safety scripts remain shell-owned;
- `CheckoutShellRenderer` binds the manifest into the shell;
- `checkout-shell.tpl` emits escaped deferred runtime asset tags;
- provider/legacy takeover still validates the compatibility/manifest boundary before exposing the custom process;
- production readiness remains closed and no takeover hook calls `validateOrder()`.

`tests/Browser/active-checkout-browser-contract.mjs` remains the authoritative runtime proof: it rejects any browser page error, requires successful network responses for all six OPC assets, a defined mutation client and the `jzopc:checkout:initialized` lifecycle event. The existing `jQuery is not defined` failure is therefore not suppressed or reclassified; the compatibility fix must make that unchanged browser contract pass.

`tests/Runtime/prestashop-http-router.php` exists only to make the CI PHP development server preserve normal static-vs-dynamic web-server semantics. It refuses static traversal-like paths, serves only existing GET/HEAD files through the built-in server, and routes every other request through PrestaShop Core.

The modern jQuery registration delta is not runtime-verified until a new PrestaShop 9.0/9.1 Chromium run passes on the resulting HEAD. Source/smoke success alone is insufficient.

## Invariants

- no custom shell may be considered production-ready unless all required OPC JavaScript and required Core compatibility dependencies are actually delivered and initialize without browser errors;
- native/conflicting checkout fallback remains free of OPC runtime assets;
- browser state remains non-authoritative; server cart/customer/CSRF/state-version validation and cart mutex semantics remain unchanged;
- third-party payment/carrier forms remain Core/module-owned and are not recreated by OPC;
- the OPC module never creates orders directly and never calls `PaymentModule::validateOrder()`;
- `INTEGRATION_SHELL_READY` remains `false` until this and the remaining final payment-completion gates are genuinely proven.

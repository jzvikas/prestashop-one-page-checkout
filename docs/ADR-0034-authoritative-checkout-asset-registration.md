# ADR-0034: Required OPC assets are owned by the custom checkout shell

## Status

Provisional; shell-owned delivery is implemented and source-contracted, while executed Chromium proof is still required on the latest PrestaShop 9.0/9.1 runtime HEAD before this boundary can be treated as verified.

## Context

The installed PrestaShop 9 browser matrix exposed a lifecycle mismatch that source-only contracts did not reveal. On the legacy 9.0/9.1 path the custom OPC shell rendered with valid server-generated cart, CSRF, state-version and mutation endpoint bindings, while none of the six required `jzonepagecheckout` JavaScript assets appeared in the document. Without those scripts the browser cannot initialize mutation serialization, stale-state handling, payment handoff or final-submit guards.

Two Core asset-manager hypotheses were then executed and disproved:

1. re-registering the keyed assets from `actionCheckoutRender` was too late; the custom shell still rendered with `scriptSources: []`;
2. changing the early `actionFrontControllerSetMedia` controller guard from `instanceof OrderController` to the stable `php_self === 'order'` identity also did not close the defect. PrestaShop Runtime run `34015527664` on commit `352978569ca74fa60eee57127c2cd43e4e12f408` again reached the active 9.1.5 Chromium contract with a valid OPC bootstrap but no OPC script tags or network responses.

The second run is important evidence: installation, installed contracts, sequential MariaDB finalization reservation, process-concurrent MariaDB reservation, Core process adapter, failure isolation, Smarty shell, session, fail-closed HTTP and active fixture preparation all succeeded before Chromium failed solely on required asset delivery. The same run's 9.2.0-beta.1 native-OPC conflict scenario completed successfully and intentionally skipped active OPC browser takeover.

Therefore page-level Core asset registration cannot be the safety boundary for a checkout process that is itself selected later in the request lifecycle.

After moving delivery into the shell, executed PrestaShop Runtime run `34016579028` on commit `f201126f913bcd7cc5c573bba828c441c596943e` proved the next layer of the contract. The 9.1.5 browser received all six expected `<script>` URLs from the OPC shell, but `payment-controller.js` returned HTTP 404 and no mutation lifecycle initialized. Inspection of the runtime harness showed that the PHP development server was started with `/tmp/prestashop/index.php` as an unconditional router, so even an existing `/modules/jzonepagecheckout/views/js/*.js` request was sent through the PrestaShop Front Office controller and converted to an application 404 instead of being served as a static file. The same run had already passed installation, sequential and process-concurrent MariaDB reservation contracts, Core process adapter, integration failure isolation, Smarty shell, session, fail-closed HTTP and active fixture preparation before reaching this browser failure.

That 404 is a runtime-harness transport defect, not evidence that the production module URL is wrong. A normal web server serves an existing module asset directly and sends dynamic checkout routes to PrestaShop. The runtime server now mirrors that split through `tests/Runtime/prestashop-http-router.php`: safe GET/HEAD requests for existing files are returned to PHP's built-in static server, while dynamic, missing or traversal-like requests continue through the real PrestaShop `index.php` entry point. This test router does not alter production module code or browser assertions.

## Decision

The rendered custom checkout shell is now the authoritative delivery boundary for the six required external JavaScript files.

`CheckoutFrontendAssetRegistrar` owns a single manifest of required asset paths and derives their URLs from PrestaShop's module path. `CheckoutShellRenderer` resolves that manifest before rendering the shell and passes it to `checkout-shell.tpl`. The template emits escaped, same-origin, deferred `<script>` elements only after the OPC root has been rendered.

This has three safety properties:

- if shell preparation cannot resolve the required asset manifest, shell preparation throws before provider exposure / legacy process replacement and the existing request-local fallback keeps Core checkout authoritative;
- native Core checkout or an incompatible/native OPC conflict never renders the custom shell, so it never receives the OPC JavaScript runtime;
- the custom shell can no longer depend on a Core page-level asset queue whose lifecycle precedes legacy checkout takeover.

The existing `register()` calls at media/provider/legacy hook boundaries are retained as fail-closed manifest validation for compatibility with already-installed hooks, but they no longer enqueue scripts through `FrontController::registerJavascript()`. This prevents duplicate execution on themes or future Core versions where an early page-level registration would otherwise succeed. The shell is the sole delivery mechanism.

No payment form ownership, carrier mechanics, mutation authority, reservation semantics or Core order creation path changes. The OPC module still never calls `PaymentModule::validateOrder()` or creates orders directly. `INTEGRATION_SHELL_READY` remains `false` in production source.

## Verification

`tests/Smoke/CheckoutTakeoverAssetRegistrationContractSmokeTest.php` locks all of the following:

- the manifest contains all six required runtime files;
- the manifest resolves from the module path;
- the compatibility `register()` boundary does not call `registerJavascript()`;
- `CheckoutShellRenderer` binds the manifest into the shell;
- `checkout-shell.tpl` emits escaped deferred runtime asset tags;
- provider/legacy takeover still validates the manifest before exposing the custom process;
- production readiness remains closed and no takeover hook calls `validateOrder()`.

`tests/Browser/active-checkout-browser-contract.mjs` remains the authoritative runtime proof: it requires successful network responses for all six assets, a defined mutation client and the `jzopc:checkout:initialized` lifecycle event. The browser gate was deliberately not weakened after the harness-level 404.

`tests/Runtime/prestashop-http-router.php` exists only to make the CI PHP development server preserve normal static-vs-dynamic web-server semantics. It refuses static traversal-like paths, serves only existing GET/HEAD files through the built-in server, and routes every other request through PrestaShop Core.

This decision is not runtime-verified until a new PrestaShop 9.0/9.1 Chromium run passes the existing browser gate on the latest HEAD. A source-only pass or the earlier shell-tag proof is insufficient.

## Invariants

- no custom shell may be considered production-ready unless all required OPC JavaScript is actually delivered and initialized;
- native/conflicting checkout fallback remains free of OPC runtime assets;
- browser state remains non-authoritative; server cart/customer/CSRF/state-version validation and cart mutex semantics remain unchanged;
- third-party payment/carrier forms remain Core/module-owned and are not recreated by OPC;
- the OPC module never creates orders directly and never calls `PaymentModule::validateOrder()`;
- `INTEGRATION_SHELL_READY` remains `false` until this and the remaining final payment-completion gates are genuinely proven.

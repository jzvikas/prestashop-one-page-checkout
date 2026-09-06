<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$package = file_get_contents($root . '/tests/Browser/package.json');
$browser = file_get_contents($root . '/tests/Browser/active-checkout-browser-contract.mjs');
$workflow = file_get_contents($root . '/.github/workflows/prestashop-runtime.yml');
$mutationClient = file_get_contents($root . '/views/js/checkout-mutation-client.js');
$identityTemplate = file_get_contents($root . '/views/templates/front/sections/identity.tpl');
$module = file_get_contents($root . '/jzonepagecheckout.php');

function assertActiveBrowserRuntime(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

foreach ([$package, $browser, $workflow, $mutationClient, $identityTemplate, $module] as $source) {
    assertActiveBrowserRuntime(
        is_string($source) && $source !== '',
        'active browser runtime source must be readable',
    );
}

assertActiveBrowserRuntime(
    str_contains($package, '"playwright": "1.63.0"'),
    'browser runtime must pin one explicit Playwright version rather than floating latest',
);
assertActiveBrowserRuntime(
    str_contains($workflow, '--domain=localhost:8080')
        && str_contains($workflow, 'JZOPC_BROWSER_BASE_URL: http://localhost:8080')
        && str_contains($workflow, 'php -S 127.0.0.1:8080'),
    'installed shop domain, browser base URL and loopback server must agree on port 8080',
);
assertActiveBrowserRuntime(
    str_contains($workflow, "- ps_ref: '9.2.0-beta.1'")
        && str_contains($workflow, "native_opc: '1'")
        && substr_count($workflow, "if: matrix.native_opc == '0'") >= 7
        && str_contains($workflow, "if: matrix.family == '9.1' && matrix.native_opc == '0'"),
    'native-OPC conflict rows must remain fail-closed compatibility scenarios and must not enter active takeover/browser fixture steps',
);
assertActiveBrowserRuntime(
    str_contains($browser, "from 'playwright'")
        && str_contains($browser, 'chromium.launch({ headless: true })'),
    'active browser contract must execute a real headless Chromium instance',
);
assertActiveBrowserRuntime(
    str_contains($browser, 'JZOPC_ACTIVE_FIXTURE_ROOT')
        && str_contains($browser, "'/tmp/jzopc-active-fixture'")
        && str_contains($browser, "'.jzopc-runtime-failure-service'"),
    'browser failure injection must be restricted to the disposable active fixture',
);
assertActiveBrowserRuntime(
    str_contains($browser, "new URL('/cart', baseUrl)")
        && str_contains($browser, "cartUrl.searchParams.set('add', '1')")
        && str_contains($browser, "cartUrl.searchParams.set('ajax', '1')")
        && str_contains($browser, "cartUrl.searchParams.set('id_product', String(productId))"),
    'browser cart must be created through the real Core CartController Ajax route without rendering an unrelated native theme page',
);
assertActiveBrowserRuntime(
    str_contains($mutationClient, "this.dispatch('jzopc:checkout:initialized'")
        && str_contains($browser, "document.addEventListener('jzopc:checkout:initialized'")
        && str_contains($browser, 'page.addInitScript'),
    'browser contract must observe the real checkout initialized lifecycle before module scripts run',
);
assertActiveBrowserRuntime(
    str_contains($mutationClient, "this.dispatch('jzopc:checkout:validation-failed'")
        && str_contains($browser, "document.addEventListener('jzopc:checkout:validation-failed'")
        && str_contains($browser, "type: 'validation-failed'"),
    'browser contract must observe guarded server validation failures through the real checkout lifecycle',
);
assertActiveBrowserRuntime(
    str_contains($identityTemplate, 'data-jzopc-identity-form="create"')
        && str_contains($identityTemplate, 'data-jzopc-identity-form="login"')
        && str_contains($browser, "'[data-jzopc-identity-form=\"create\"] form'")
        && str_contains($browser, "'[data-jzopc-identity-form=\"login\"] form'"),
    'identity browser coverage must use stable OPC wrappers around the Core create/login forms',
);
assertActiveBrowserRuntime(
    str_contains($browser, 'form.noValidate = true;')
        && str_contains($browser, 'form.requestSubmit();')
        && str_contains($browser, 'empty identity submit did not return server validation errors')
        && str_contains($browser, 'validation failure unexpectedly navigated away from /order')
        && str_contains($browser, 'server validation changed the active Core cart binding'),
    'identity validation browser path must reach the server mutation endpoint while preserving page/cart state on recoverable validation errors',
);
assertActiveBrowserRuntime(
    str_contains($browser, "page.locator('[data-jzopc-checkout]')")
        && str_contains($browser, "page.locator('#checkout-personal-information-step')")
        && str_contains($browser, 'initializedCount !== 0'),
    'browser contract must distinguish healthy OPC from native Core fallback and require OPC JavaScript to stay dormant on fallback',
);
foreach ([
    'payment-controller.js',
    'checkout-mutation-client.js',
    'final-submit-controller.js',
    'ordinary-payment-submit-guard.js',
    'binary-payment-controller.js',
    'payment-handoff-ambiguity-guard.js',
] as $asset) {
    assertActiveBrowserRuntime(
        str_contains($browser, "'{$asset}'"),
        sprintf('browser contract must verify frontend asset %s', $asset),
    );
}
assertActiveBrowserRuntime(
    str_contains($browser, "'data-jzopc-identity-url'")
        && str_contains($browser, "'data-jzopc-address-url'")
        && str_contains($browser, "'data-jzopc-address-save-url'")
        && str_contains($browser, "'data-jzopc-carrier-url'")
        && str_contains($browser, "'data-jzopc-payment-url'")
        && str_contains($browser, "'data-jzopc-agreements-url'")
        && str_contains($browser, "'data-jzopc-finalization-url'")
        && str_contains($browser, 'resolved.origin !== baseOrigin'),
    'all server-generated checkout endpoints must be present and constrained to the runtime browser origin',
);
assertActiveBrowserRuntime(
    str_contains($browser, "fs.writeFileSync(serviceFailureMarker, 'browser\\n', { flag: 'wx' })")
        && str_contains($browser, "await assertNativeFallback('service-fallback')")
        && str_contains($browser, "await assertHealthyCheckout('recovered-opc', false)")
        && str_contains($browser, 'recoveredState.cartId !== initialState.cartId'),
    'browser contract must inject one shell-service failure and prove native fallback plus same-cart recovery',
);
assertActiveBrowserRuntime(
    str_contains($browser, 'if (fs.existsSync(serviceFailureMarker))')
        && str_contains($browser, 'fs.unlinkSync(serviceFailureMarker)'),
    'browser failure marker must have nested and outer cleanup protection',
);
assertActiveBrowserRuntime(
    str_contains($browser, "page.on('pageerror'")
        && str_contains($browser, 'browser JavaScript error:'),
    'browser runtime must fail on real page JavaScript exceptions once it enters the OPC checkout/fallback page lifecycle',
);
assertActiveBrowserRuntime(
    !str_contains($browser, 'finalizationAction')
        && !str_contains($browser, 'validateOrder(')
        && !str_contains($browser, 'data-jzopc-final-submit]')
        && !str_contains($browser, "'/module/jzonepagecheckout/finalize'")
        && !str_contains($browser, 'PaymentModule'),
    'identity/takeover/fallback browser gate must never initiate finalization, payment or order creation',
);

$activeServerPosition = strpos($workflow, 'Start active Front Office HTTP server');
$browserInstallPosition = strpos($workflow, 'Install active browser contract dependencies');
$browserRunPosition = strpos($workflow, 'Execute active checkout Chromium contract');
$activeHttpPosition = strpos($workflow, 'Execute active checkout failure fallback HTTP contract');
assertActiveBrowserRuntime(
    $browserInstallPosition !== false
        && $activeServerPosition !== false
        && $browserRunPosition !== false
        && $activeHttpPosition !== false
        && $browserInstallPosition < $activeServerPosition
        && $activeServerPosition < $browserRunPosition
        && $browserRunPosition < $activeHttpPosition,
    'browser dependencies/server/Chromium contract must run before the active HTTP contract removes the fixture product',
);
assertActiveBrowserRuntime(
    str_contains($workflow, 'npm install --no-package-lock --ignore-scripts --no-audit --no-fund')
        && str_contains($workflow, './node_modules/.bin/playwright install --with-deps chromium')
        && str_contains($workflow, 'run: node active-checkout-browser-contract.mjs'),
    'runtime matrix must install only the pinned test dependency/browser and execute the standalone browser contract',
);
assertActiveBrowserRuntime(
    str_contains($module, 'private const INTEGRATION_SHELL_READY = false;')
        && !str_contains($module, 'private const INTEGRATION_SHELL_READY = true;'),
    'production integration readiness must remain closed while browser runtime evidence is pending',
);

fwrite(STDOUT, "Active Chromium browser runtime source contract OK.\n");

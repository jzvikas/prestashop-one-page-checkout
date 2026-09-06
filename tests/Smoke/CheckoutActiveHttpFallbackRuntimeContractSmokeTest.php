<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$builder = file_get_contents($root . '/tests/Runtime/build-active-checkout-fixture.sh');
$instrumenter = file_get_contents($root . '/tests/Runtime/InstrumentActiveCheckoutFailureFixture.php');
$browser = file_get_contents($root . '/tests/Browser/active-checkout-browser-contract.mjs');
$persistenceControl = file_get_contents($root . '/tests/Runtime/ActiveCheckoutPersistenceFailureControl.php');
$legacyHttpDiagnostic = file_get_contents($root . '/tests/Runtime/ActiveCheckoutFallbackHttpContract.php');
$workflow = file_get_contents($root . '/.github/workflows/prestashop-runtime.yml');
$module = file_get_contents($root . '/jzonepagecheckout.php');
$shellRenderer = file_get_contents($root . '/src/Integration/CheckoutShellRenderer.php');
$templateRenderer = file_get_contents($root . '/src/Checkout/Rendering/PrestaShopCheckoutTemplateRenderer.php');
$assetRegistrar = file_get_contents($root . '/src/Integration/CheckoutFrontendAssetRegistrar.php');

function assertActiveFallbackRuntime(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

foreach ([
    $builder,
    $instrumenter,
    $browser,
    $persistenceControl,
    $legacyHttpDiagnostic,
    $workflow,
    $module,
    $shellRenderer,
    $templateRenderer,
    $assetRegistrar,
] as $source) {
    assertActiveFallbackRuntime(
        is_string($source) && $source !== '',
        'active fallback runtime source must be readable',
    );
}

assertActiveFallbackRuntime(
    str_contains($builder, 'JZOPC_RUNTIME_ACTIVE_FIXTURE')
        && str_contains($builder, '/tmp/jzopc-active-fixture')
        && str_contains($builder, 'private const INTEGRATION_SHELL_READY = false;')
        && str_contains($builder, 'private const INTEGRATION_SHELL_READY = true;'),
    'active checkout readiness may be opened only inside the explicit temporary runtime fixture',
);
assertActiveFallbackRuntime(
    str_contains($builder, 'InstrumentActiveCheckoutFailureFixture.php')
        && str_contains($builder, "grep -Fq '.jzopc-runtime-failure-'")
        && str_contains($builder, 'Source runtime code changed while instrumenting temporary fixture'),
    'fixture builder must install failure instrumentation only after proving production runtime sources are marker-free',
);
assertActiveFallbackRuntime(
    str_contains($instrumenter, "getenv('JZOPC_RUNTIME_ACTIVE_FIXTURE') !== '1'")
        && str_contains($instrumenter, "'/tmp/jzopc-active-fixture'")
        && str_contains($instrumenter, 'is_link($path)')
        && str_contains($instrumenter, 'substr_count($source, $patch[\'anchor\']) !== 1'),
    'failure instrumenter must be opt-in, temporary-path-only, symlink-safe and fail closed on source anchor drift',
);
foreach ([
    '.jzopc-runtime-failure-service',
    '.jzopc-runtime-failure-template',
    '.jzopc-runtime-failure-assets',
] as $marker) {
    assertActiveFallbackRuntime(
        str_contains($instrumenter, $marker) && str_contains($browser, $marker),
        sprintf('browser-authoritative failure matrix must wire marker %s through instrumentation and Chromium', $marker),
    );
    assertActiveFallbackRuntime(
        !str_contains($shellRenderer, $marker)
            && !str_contains($templateRenderer, $marker)
            && !str_contains($assetRegistrar, $marker),
        sprintf('production runtime source must not contain test marker %s', $marker),
    );
}
assertActiveFallbackRuntime(
    str_contains($instrumenter, "throw new \\RuntimeException('Injected active checkout shell service failure.')")
        && str_contains($instrumenter, "__jzopc_runtime_missing_template__.tpl")
        && str_contains($instrumenter, "throw new RuntimeException('Injected active checkout asset manifest validation failure.')"),
    'temporary fixture must inject service, real Smarty-template and shell-manifest failures at their production boundaries',
);
assertActiveFallbackRuntime(
    str_contains($persistenceControl, "getenv('JZOPC_RUNTIME_ACTIVE_FIXTURE') !== '1'")
        && str_contains($persistenceControl, "\$shopRoot !== '/tmp/prestashop'")
        && str_contains($persistenceControl, "realpath(\$shopRoot . '/modules/jzonepagecheckout/jzonepagecheckout.php')")
        && str_contains($persistenceControl, "'/tmp/jzopc-active-fixture/jzonepagecheckout.php'")
        && str_contains($persistenceControl, '$schema->uninstall()')
        && str_contains($persistenceControl, '$schema->install()'),
    'persistence failure control must refuse production paths and mutate only the disposable module schema boundary',
);
assertActiveFallbackRuntime(
    str_contains($browser, "setPersistenceFailure('drop')")
        && str_contains($browser, "await assertNativeFallback('persistence-fallback')")
        && str_contains($browser, "setPersistenceFailure('restore')")
        && str_contains($browser, "await assertRecoveredSameCart('persistence-recovered', initialState.cartId)"),
    'persistence failure and recovery must execute in the same real Chromium checkout session',
);
assertActiveFallbackRuntime(
    str_contains($browser, 'for (const [mode, marker] of failureMarkers)')
        && str_contains($browser, 'fs.writeFileSync(marker, `${mode}\\n`, { flag: \'wx\' })')
        && str_contains($browser, "await assertNativeFallback(`${mode}-fallback`)")
        && str_contains($browser, "await assertRecoveredSameCart(`${mode}-recovered`, initialState.cartId)"),
    'service/template/assets failures must each prove native Core fallback and same-cart Chromium recovery',
);
assertActiveFallbackRuntime(
    str_contains($browser, "page.locator('#checkout-personal-information-step')")
        && str_contains($browser, 'initializedCount !== 0')
        && str_contains($browser, 'recovered OPC did not preserve the same Core browser cart'),
    'fallback proof must distinguish native Core checkout from OPC and preserve exact browser cart identity after recovery',
);
assertActiveFallbackRuntime(
    str_contains($workflow, 'JZOPC_ACTIVE_FIXTURE_ROOT: /tmp/jzopc-active-fixture')
        && str_contains($workflow, 'JZOPC_PRESTASHOP_ROOT: /tmp/prestashop')
        && str_contains($workflow, "JZOPC_RUNTIME_ACTIVE_FIXTURE: '1'")
        && str_contains($workflow, 'run: node active-checkout-browser-contract.mjs')
        && !str_contains($workflow, 'Execute active checkout failure fallback HTTP contract')
        && !str_contains($workflow, 'ActiveCheckoutFallbackHttpContract.php'),
    'installed runtime must treat Chromium as the active fallback release gate and must not invoke the transport-specific PHP HTTP diagnostic',
);
assertActiveFallbackRuntime(
    str_contains($legacyHttpDiagnostic, 'function activeCheckoutResponseDiagnostics(array $response): string')
        && str_contains($legacyHttpDiagnostic, 'ActiveCheckoutHttpSession')
        && !str_contains($legacyHttpDiagnostic, 'validateOrder('),
    'legacy PHP HTTP harness may remain as source diagnostic history but must stay non-ordering and non-authoritative',
);
assertActiveFallbackRuntime(
    !str_contains($browser, 'validateOrder(')
        && !str_contains($browser, 'PaymentModule')
        && !str_contains($persistenceControl, 'validateOrder(')
        && !str_contains($persistenceControl, 'INSERT INTO')
        && !str_contains($persistenceControl, 'PaymentModule'),
    'browser fallback and persistence controls must never initiate payment finalization or order creation',
);
assertActiveFallbackRuntime(
    str_contains($module, 'private const INTEGRATION_SHELL_READY = false;')
        && !str_contains($module, 'private const INTEGRATION_SHELL_READY = true;'),
    'production repository readiness must remain closed',
);

fwrite(STDOUT, "Browser-authoritative active fallback runtime source contract OK.\n");

<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$builder = file_get_contents($root . '/tests/Runtime/build-active-checkout-fixture.sh');
$instrumenter = file_get_contents($root . '/tests/Runtime/InstrumentActiveCheckoutFailureFixture.php');
$setup = file_get_contents($root . '/tests/Runtime/PrepareActiveCheckoutHttpFixture.php');
$http = file_get_contents($root . '/tests/Runtime/ActiveCheckoutFallbackHttpContract.php');
$workflow = file_get_contents($root . '/.github/workflows/prestashop-runtime.yml');
$module = file_get_contents($root . '/jzonepagecheckout.php');
$shellRenderer = file_get_contents($root . '/src/Integration/CheckoutShellRenderer.php');
$templateRenderer = file_get_contents($root . '/src/Checkout/Rendering/PrestaShopCheckoutTemplateRenderer.php');
$assetRegistrar = file_get_contents($root . '/src/Integration/CheckoutFrontendAssetRegistrar.php');

function assertActiveHttpFallbackRuntime(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

foreach ([
    $builder,
    $instrumenter,
    $setup,
    $http,
    $workflow,
    $module,
    $shellRenderer,
    $templateRenderer,
    $assetRegistrar,
] as $source) {
    assertActiveHttpFallbackRuntime(
        is_string($source) && $source !== '',
        'active HTTP fallback runtime source must be readable',
    );
}

assertActiveHttpFallbackRuntime(
    str_contains($builder, 'JZOPC_RUNTIME_ACTIVE_FIXTURE')
        && str_contains($builder, '/tmp/jzopc-active-fixture')
        && str_contains($builder, 'private const INTEGRATION_SHELL_READY = false;')
        && str_contains($builder, 'private const INTEGRATION_SHELL_READY = true;'),
    'active checkout readiness may be opened only inside the explicit temporary runtime fixture',
);
assertActiveHttpFallbackRuntime(
    str_contains($builder, 'InstrumentActiveCheckoutFailureFixture.php')
        && str_contains($builder, "grep -Fq '.jzopc-runtime-failure-'")
        && str_contains($builder, 'Source runtime code changed while instrumenting temporary fixture'),
    'fixture builder must install failure instrumentation only after proving production runtime sources are marker-free',
);
assertActiveHttpFallbackRuntime(
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
    assertActiveHttpFallbackRuntime(
        str_contains($instrumenter, $marker) && str_contains($http, $marker),
        sprintf('active failure matrix must wire marker %s through both instrumentation and request contract', $marker),
    );
    assertActiveHttpFallbackRuntime(
        !str_contains($shellRenderer, $marker)
            && !str_contains($templateRenderer, $marker)
            && !str_contains($assetRegistrar, $marker),
        sprintf('production runtime source must not contain test marker %s', $marker),
    );
}
assertActiveHttpFallbackRuntime(
    str_contains($instrumenter, "throw new \\RuntimeException('Injected active checkout shell service failure.')")
        && str_contains($instrumenter, "__jzopc_runtime_missing_template__.tpl")
        && str_contains($instrumenter, "throw new RuntimeException('Injected active checkout asset manifest validation failure.')"),
    'temporary fixture must inject service, real Smarty-template and shell-manifest failures at their production boundaries',
);
assertActiveHttpFallbackRuntime(
    str_contains($assetRegistrar, '$this->shellJavascriptUrls();')
        && !str_contains($assetRegistrar, '$controller->addJquery();')
        && !str_contains($assetRegistrar, '\\Media::getJqueryPath()')
        && !str_contains($assetRegistrar, '$controller->registerJavascript(')
        && str_contains($instrumenter, '$controller = $context->controller ?? null;'),
    'active checkout asset boundary must validate only the six-file shell manifest while Core/theme compatibility assets remain Core-owned',
);
assertActiveHttpFallbackRuntime(
    str_contains($setup, "str_starts_with(\$modulePath, '/tmp/jzopc-active-fixture')")
        && str_contains($http, "str_starts_with(\$modulePath, '/tmp/jzopc-active-fixture')"),
    'setup and HTTP execution must both refuse the production/source module tree',
);
assertActiveHttpFallbackRuntime(
    str_contains($setup, 'Configuration::updateValue(')
        && str_contains($setup, 'JzOnePageCheckout::CONFIG_CHECKOUT_ENABLED')
        && str_contains($setup, '$shopGroupId,')
        && str_contains($setup, '$shopId,'),
    'temporary activation must be explicitly scoped to the runtime shop/group',
);
assertActiveHttpFallbackRuntime(
    str_contains($setup, "Module::isEnabled('ps_onepagecheckout')")
        && str_contains($setup, '$nativeOpc->disable()'),
    'temporary 9.2 active fixture must remove the native provider conflict through normal module state',
);
assertActiveHttpFallbackRuntime(
    str_contains($setup, 'new Product()')
        && str_contains($setup, '$product->add()')
        && str_contains($setup, '$product->addToCategories([$homeCategoryId])')
        && str_contains($setup, 'StockAvailable::setQuantity('),
    'runtime product fixture must use PrestaShop Core product/category/stock APIs',
);
assertActiveHttpFallbackRuntime(
    str_contains($setup, 'new Carrier()')
        && str_contains($setup, 'Carrier::SHIPPING_METHOD_FREE')
        && str_contains($setup, '$carrier->addZone(')
        && str_contains($setup, "'carrier_group'")
        && str_contains($setup, "'carrier_shop'")
        && str_contains($setup, "'PS_CARRIER_DEFAULT'"),
    'fixtures=0 runtime setup must create a Core carrier with real shop, zone and customer-group associations',
);
assertActiveHttpFallbackRuntime(
    str_contains($http, "'/cart?' . http_build_query(")
        && str_contains($http, "'add' => 1")
        && str_contains($http, "'ajax' => 1")
        && str_contains($http, "'id_product' => \$productId")
        && str_contains($http, "CURLOPT_COOKIEFILE => ''")
        && str_contains($http, 'CURLOPT_COOKIELIST, $cookie')
        && str_contains($http, 'CURLINFO_COOKIELIST')
        && !str_contains($http, 'CURLOPT_COOKIEJAR')
        && str_contains($http, 'CURLOPT_USERAGENT'),
    'browser cart fixture must use real Core AJAX cart mutation, carry one cookie session across isolated transfers, and use a non-bot user agent',
);
assertActiveHttpFallbackRuntime(
    str_contains($http, 'function activeCheckoutResponseDiagnostics(array $response): string')
        && str_contains($http, "parse_url(\$effectiveUrl, PHP_URL_PATH)")
        && str_contains($http, "'status=%d method=%s path=%s content_type=%s captured_bytes=%d transfer_bytes=%d content_length=%d opc=%d core_checkout=%d cart_page=%d empty_cart=%d'")
        && str_contains($http, "str_contains(\$body, 'data-jzopc-checkout')")
        && str_contains($http, "str_contains(\$body, 'id=\"checkout-personal-information-step\"')")
        && str_contains($http, 'CURLINFO_EFFECTIVE_METHOD')
        && str_contains($http, 'CURLINFO_SIZE_DOWNLOAD')
        && str_contains($http, 'CURLINFO_CONTENT_LENGTH_DOWNLOAD')
        && !str_contains($http, "fwrite(STDERR, \$response['body'])")
        && !str_contains($http, "implode(\"\\n\", \$session->cookies())"),
    'fallback diagnostics must expose only structural method/response/transfer state and must not log response bodies or cookie values',
);
assertActiveHttpFallbackRuntime(
    str_contains($http, "str_contains(\$response['body'], 'data-jzopc-checkout')")
        && str_contains($http, "str_contains(\$response['body'], 'id=\"checkout-personal-information-step\"')")
        && str_contains($http, 'activeCheckoutResponseDiagnostics($response)'),
    'HTTP contract must distinguish healthy OPC takeover from Core native checkout and emit safe diagnostics on structural mismatch',
);
assertActiveHttpFallbackRuntime(
    str_contains($http, '$schema->uninstall()')
        && str_contains($http, '$schema->install()')
        && str_contains($http, "expectNativeFallback(\$fallback, 'Persistence-failure')")
        && str_contains($http, "expectHealthyOpc(\$recovered, 'Persistence-recovered')"),
    'HTTP contract must inject a real module persistence failure and prove request-local recovery on the same browser/cart',
);
assertActiveHttpFallbackRuntime(
    str_contains($http, 'foreach ($failureMarkers as $mode => $markerPath)')
        && str_contains($http, 'activateFailureMarker($markerPath, $fixtureRoot, $mode)')
        && str_contains($http, "expectNativeFallback(\$modeFallback, ucfirst(\$mode) . '-failure')")
        && str_contains($http, "expectHealthyOpc(\$modeRecovered, ucfirst(\$mode) . '-recovered')")
        && str_contains($http, 'deactivateFailureMarker($markerPath, $mode)'),
    'service/template/assets failures must each prove native fallback and same-cart recovery with marker cleanup',
);
assertActiveHttpFallbackRuntime(
    str_contains($http, 'finally {')
        && str_contains($http, 'JzOnePageCheckout::CONFIG_CHECKOUT_ENABLED')
        && str_contains($http, 'false,')
        && str_contains($http, '$product->delete()')
        && str_contains($http, 'Cleanup could not remove %s failure marker.'),
    'active fallback test must restore markers/schema/config/product state through cleanup boundaries',
);
assertActiveHttpFallbackRuntime(
    !str_contains($setup, 'validateOrder(')
        && !str_contains($http, 'validateOrder(')
        && !str_contains($setup, 'INSERT INTO')
        && !str_contains($http, 'INSERT INTO')
        && !str_contains($http, 'finalizationAction'),
    'active fallback harness must not create orders, write cart/order SQL directly or exercise payment finalization',
);

$closedHttpPosition = strpos($workflow, 'Execute fail-closed Front Office HTTP contract');
$activeBuildPosition = strpos($workflow, 'Build temporary active checkout fixture');
$activeHttpPosition = strpos($workflow, 'Execute active checkout failure fallback HTTP contract');
assertActiveHttpFallbackRuntime(
    $closedHttpPosition !== false
        && $activeBuildPosition !== false
        && $activeHttpPosition !== false
        && $closedHttpPosition < $activeBuildPosition
        && $activeBuildPosition < $activeHttpPosition,
    'runtime workflow must prove closed production behavior before constructing/running the temporary active fixture',
);
assertActiveHttpFallbackRuntime(
    str_contains($workflow, "JZOPC_RUNTIME_ACTIVE_FIXTURE: '1'")
        && str_contains($workflow, 'bash tests/Runtime/build-active-checkout-fixture.sh')
        && str_contains($workflow, '/tmp/jzopc-active-fixture')
        && str_contains($workflow, 'PrepareActiveCheckoutHttpFixture.php')
        && str_contains($workflow, 'ActiveCheckoutFallbackHttpContract.php'),
    'runtime matrix must create and execute only the explicit temporary active checkout harness',
);
assertActiveHttpFallbackRuntime(
    str_contains($workflow, 'rm -rf /tmp/prestashop/modules/jzonepagecheckout')
        && str_contains($workflow, 'ln -s /tmp/jzopc-active-fixture /tmp/prestashop/modules/jzonepagecheckout')
        && str_contains($workflow, 'php bin/console cache:clear --no-warmup'),
    'active HTTP phase must remount the installed module to the temporary copy and rebuild runtime cache',
);
assertActiveHttpFallbackRuntime(
    str_contains($module, 'private const INTEGRATION_SHELL_READY = false;')
        && !str_contains($module, 'private const INTEGRATION_SHELL_READY = true;'),
    'production repository readiness must remain closed',
);

fwrite(STDOUT, "Active HTTP fallback runtime source contract OK.\n");
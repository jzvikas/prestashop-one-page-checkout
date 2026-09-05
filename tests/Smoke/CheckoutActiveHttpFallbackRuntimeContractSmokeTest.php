<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$builder = file_get_contents($root . '/tests/Runtime/build-active-checkout-fixture.sh');
$setup = file_get_contents($root . '/tests/Runtime/PrepareActiveCheckoutHttpFixture.php');
$http = file_get_contents($root . '/tests/Runtime/ActiveCheckoutFallbackHttpContract.php');
$workflow = file_get_contents($root . '/.github/workflows/prestashop-runtime.yml');
$module = file_get_contents($root . '/jzonepagecheckout.php');

function assertActiveHttpFallbackRuntime(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

foreach ([$builder, $setup, $http, $workflow, $module] as $source) {
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
    str_contains($http, "'/cart?' . http_build_query(")
        && str_contains($http, "'add' => 1")
        && str_contains($http, "'id_product' => \$productId")
        && str_contains($http, 'CURLOPT_COOKIEJAR')
        && str_contains($http, 'CURLOPT_COOKIEFILE')
        && str_contains($http, 'CURLOPT_USERAGENT'),
    'browser cart fixture must be created through the real Core CartController with one cookie session and non-bot user agent',
);
assertActiveHttpFallbackRuntime(
    str_contains($http, "str_contains(\$response['body'], 'data-jzopc-checkout')")
        && str_contains($http, "str_contains(\$response['body'], 'id=\"checkout-personal-information-step\"')"),
    'HTTP contract must distinguish healthy OPC takeover from an actual Core native checkout step',
);
assertActiveHttpFallbackRuntime(
    str_contains($http, '$schema->uninstall()')
        && str_contains($http, '$schema->install()')
        && str_contains($http, "expectNativeFallback(\$fallback, 'Persistence-failure')")
        && str_contains($http, "expectHealthyOpc(\$recovered, 'Recovered')"),
    'HTTP contract must inject a real module persistence failure and prove request-local recovery on the same browser/cart',
);
assertActiveHttpFallbackRuntime(
    str_contains($http, 'finally {')
        && str_contains($http, 'JzOnePageCheckout::CONFIG_CHECKOUT_ENABLED')
        && str_contains($http, 'false,')
        && str_contains($http, '$product->delete()'),
    'active fallback test must restore schema/config/product state through a cleanup boundary',
);
assertActiveHttpFallbackRuntime(
    !str_contains($setup, 'validateOrder(')
        && !str_contains($http, 'validateOrder(')
        && !str_contains($setup, 'INSERT INTO')
        && !str_contains($http, 'INSERT INTO')
        && !str_contains($http, 'finalizationAction'),
    'active fallback harness must not create orders, write cart/order SQL directly or exercise payment finalization',
);
assertActiveHttpFallbackRuntime(
    str_contains($module, 'private const INTEGRATION_SHELL_READY = false;')
        && !str_contains($module, 'private const INTEGRATION_SHELL_READY = true;'),
    'production repository readiness must remain closed',
);

fwrite(STDOUT, "Active HTTP fallback runtime source contract OK.\n");

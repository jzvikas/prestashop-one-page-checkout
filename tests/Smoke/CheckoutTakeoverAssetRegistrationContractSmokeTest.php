<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$module = file_get_contents($root . '/jzonepagecheckout.php');
$registrar = file_get_contents($root . '/src/Integration/CheckoutFrontendAssetRegistrar.php');
$renderer = file_get_contents($root . '/src/Integration/CheckoutShellRenderer.php');
$template = file_get_contents($root . '/views/templates/front/checkout-shell.tpl');

function assertTakeoverAssets(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

foreach ([$module, $registrar, $renderer, $template] as $source) {
    assertTakeoverAssets(is_string($source) && $source !== '', 'checkout asset contract source must be readable');
}

$providerStart = strpos($module, 'public function hookActionCheckoutBuildProcess');
$legacyStart = strpos($module, 'public function hookActionCheckoutRender');
$mediaStart = strpos($module, 'public function hookActionFrontControllerSetMedia');
$validateStart = strpos($module, 'public function hookActionValidateOrderAfter');
assertTakeoverAssets($providerStart !== false && $legacyStart !== false && $mediaStart !== false && $validateStart !== false, 'checkout integration hook boundaries must exist');

$provider = substr($module, $providerStart, $legacyStart - $providerStart);
$legacy = substr($module, $legacyStart, $mediaStart - $legacyStart);
$media = substr($module, $mediaStart, $validateStart - $mediaStart);

assertTakeoverAssets(str_contains($provider, '$registrar->register($this->context);'), 'provider takeover must validate the OPC asset manifest before shell preparation');
assertTakeoverAssets(str_contains($legacy, '$registrar->register($this->context);'), 'legacy takeover must validate the OPC asset manifest before process replacement');
assertTakeoverAssets(
    str_contains($registrar, 'private const JAVASCRIPT_PATHS = [')
        && str_contains($registrar, 'public function shellJavascriptUrls(): array')
        && str_contains($registrar, "constant('_MODULE_DIR_')")
        && str_contains($registrar, '$this->shellJavascriptUrls();'),
    'shell asset manifest must own and validate the six required OPC URLs',
);
assertTakeoverAssets(
    !str_contains($registrar, 'addJquery(')
        && !str_contains($registrar, 'registerJavascript(')
        && !str_contains($registrar, 'getJqueryPath(')
        && !str_contains($registrar, 'shellCompatibilityJavascriptUrls'),
    'OPC must not inject or duplicate PrestaShop Core/theme compatibility JavaScript',
);

foreach ([
    'payment-controller.js',
    'checkout-mutation-client.js',
    'final-submit-controller.js',
    'ordinary-payment-submit-guard.js',
    'binary-payment-controller.js',
    'payment-handoff-ambiguity-guard.js',
] as $asset) {
    assertTakeoverAssets(str_contains($registrar, "'views/js/{$asset}'"), "required checkout asset {$asset} must remain in the manifest");
}

assertTakeoverAssets(
    str_contains($renderer, "'jzopc_javascript_urls' => \$this->frontendAssets->shellJavascriptUrls()")
        && !str_contains($renderer, 'jzopc_compatibility_javascript_urls'),
    'checkout shell renderer must bind only the OPC-owned runtime manifest',
);
assertTakeoverAssets(
    str_contains($template, '{foreach $jzopc_javascript_urls as $jzopc_javascript_url}')
        && str_contains($template, 'data-jzopc-runtime-asset')
        && !str_contains($template, 'data-jzopc-core-compatibility-asset')
        && str_contains($template, 'defer'),
    'custom shell must emit only escaped deferred OPC runtime assets and leave Core compatibility to PrestaShop',
);
assertTakeoverAssets(
    str_contains($module, 'private const INTEGRATION_SHELL_READY = false;')
        && !str_contains($module, 'private const INTEGRATION_SHELL_READY = true;'),
    'production integration readiness must stay closed',
);
assertTakeoverAssets(
    !str_contains($provider, 'validateOrder(')
        && !str_contains($legacy, 'validateOrder(')
        && !str_contains($media, 'validateOrder('),
    'asset/takeover hooks must never create Core orders directly',
);

fwrite(STDOUT, "Checkout shell-owned asset delivery and Core compatibility ownership contract OK.\n");

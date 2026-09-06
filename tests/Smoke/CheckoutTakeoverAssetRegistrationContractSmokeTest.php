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
assertTakeoverAssets($providerStart < $legacyStart && $legacyStart < $mediaStart && $mediaStart < $validateStart, 'checkout integration hook ordering changed unexpectedly');

$provider = substr($module, $providerStart, $legacyStart - $providerStart);
$legacy = substr($module, $legacyStart, $mediaStart - $legacyStart);
$media = substr($module, $mediaStart, $validateStart - $mediaStart);

foreach ([$provider, $legacy, $media] as $source) {
    assertTakeoverAssets(is_string($source) && $source !== '', 'checkout integration hook source must be extractable');
}

assertTakeoverAssets(
    str_contains($provider, 'CheckoutFrontendAssetRegistrar::class')
        && str_contains($provider, '$registrar->register($this->context);')
        && strpos($provider, '$registrar->register($this->context);') < strpos($provider, '$builder = $this->get('),
    'provider takeover must validate the required compatibility/asset boundary before shell preparation',
);
assertTakeoverAssets(
    str_contains($legacy, 'CheckoutFrontendAssetRegistrar::class')
        && str_contains($legacy, '$registrar->register($this->context);')
        && strpos($legacy, '$registrar->register($this->context);') < strpos($legacy, '$adapter = $this->get('),
    'legacy takeover must validate the required compatibility/asset boundary before replacing Core checkout',
);
assertTakeoverAssets(
    str_contains($registrar, 'private const JAVASCRIPT_PATHS = [')
        && str_contains($registrar, 'public function shellJavascriptUrls(): array')
        && str_contains($registrar, "constant('_MODULE_DIR_')")
        && !str_contains($registrar, 'registerJavascript('),
    'shell asset manifest must own the six required OPC URLs without also queuing duplicate OPC scripts through Core',
);
assertTakeoverAssets(
    str_contains($registrar, "is_callable([\$controller, 'addJquery'])")
        && str_contains($registrar, '$controller->addJquery();')
        && strpos($registrar, '$controller->addJquery();') < strpos($registrar, '$this->shellJavascriptUrls();'),
    'active OPC compatibility boundary must request PrestaShop Core-owned jQuery before validating the shell runtime manifest',
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
    str_contains($renderer, 'private CheckoutFrontendAssetRegistrar $frontendAssets')
        && str_contains($renderer, "'jzopc_javascript_urls' => \$this->frontendAssets->shellJavascriptUrls()"),
    'checkout shell renderer must bind the validated asset manifest into the shell render',
);
assertTakeoverAssets(
    str_contains($template, '{foreach $jzopc_javascript_urls as $jzopc_javascript_url}')
        && str_contains($template, 'data-jzopc-runtime-asset')
        && str_contains($template, 'src="{$jzopc_javascript_url|escape:')
        && str_contains($template, 'defer'),
    'custom shell must emit escaped same-origin deferred script tags for every required runtime asset',
);
assertTakeoverAssets(
    str_contains($module, 'private const INTEGRATION_SHELL_READY = false;')
        && !str_contains($module, 'private const INTEGRATION_SHELL_READY = true;'),
    'production integration readiness must stay closed while final-submit/browser release gates remain incomplete',
);
assertTakeoverAssets(
    !str_contains($provider, 'validateOrder(')
        && !str_contains($legacy, 'validateOrder(')
        && !str_contains($media, 'validateOrder('),
    'asset/takeover hooks must never create Core orders directly',
);

fwrite(STDOUT, "Checkout shell-owned asset delivery source contract OK.\n");

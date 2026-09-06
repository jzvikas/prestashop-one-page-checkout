<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$module = file_get_contents($root . '/jzonepagecheckout.php');

function assertTakeoverAssets(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

assertTakeoverAssets(is_string($module) && $module !== '', 'module source must be readable');

$providerStart = strpos($module, 'public function hookActionCheckoutBuildProcess');
$legacyStart = strpos($module, 'public function hookActionCheckoutRender');
$mediaStart = strpos($module, 'public function hookActionFrontControllerSetMedia');
$validateStart = strpos($module, 'public function hookActionValidateOrderAfter');

assertTakeoverAssets($providerStart !== false, 'provider takeover hook must exist');
assertTakeoverAssets($legacyStart !== false, 'legacy takeover hook must exist');
assertTakeoverAssets($mediaStart !== false, 'early FrontController media hook must remain registered');
assertTakeoverAssets($validateStart !== false, 'order lifecycle hook boundary must remain present');
assertTakeoverAssets(
    $providerStart < $legacyStart && $legacyStart < $mediaStart && $mediaStart < $validateStart,
    'expected checkout integration hook ordering changed unexpectedly',
);

$provider = substr($module, $providerStart, $legacyStart - $providerStart);
$legacy = substr($module, $legacyStart, $mediaStart - $legacyStart);
$media = substr($module, $mediaStart, $validateStart - $mediaStart);

foreach ([$provider, $legacy, $media] as $source) {
    assertTakeoverAssets(is_string($source) && $source !== '', 'checkout integration hook source must be extractable');
}

assertTakeoverAssets(
    str_contains($provider, 'CheckoutFrontendAssetRegistrar::class')
        && str_contains($provider, "'provider_assets_service'")
        && str_contains($provider, '$registrar->register($this->context);'),
    'provider takeover must require and invoke the frontend asset registrar fail closed',
);
assertTakeoverAssets(
    strpos($provider, '$registrar->register($this->context);')
        < strpos($provider, '$builder = $this->get(\\Jzvikas\\OnePageCheckout\\Integration\\CheckoutProcessBuilder::class);'),
    'provider assets must be registered before shell preparation/provider exposure',
);
assertTakeoverAssets(
    str_contains($legacy, 'CheckoutFrontendAssetRegistrar::class')
        && str_contains($legacy, "'legacy_assets_service'")
        && str_contains($legacy, '$registrar->register($this->context);'),
    'legacy takeover must require and invoke the frontend asset registrar fail closed',
);
assertTakeoverAssets(
    strpos($legacy, '$registrar->register($this->context);')
        < strpos($legacy, '$adapter = $this->get(\\Jzvikas\\OnePageCheckout\\Integration\\LegacyCheckoutRenderAdapter::class);'),
    'legacy assets must be registered before the Core checkout process is replaced',
);
assertTakeoverAssets(
    str_contains($media, 'CheckoutFrontendAssetRegistrar::class')
        && str_contains($media, '$registrar->register($this->context);')
        && str_contains($media, "'assets_register'"),
    'early actionFrontControllerSetMedia registration must remain as the first asset-registration opportunity',
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

fwrite(STDOUT, "Checkout takeover asset registration source contract OK.\n");

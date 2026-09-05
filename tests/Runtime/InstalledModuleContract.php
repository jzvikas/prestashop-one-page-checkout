<?php

declare(strict_types=1);

use Jzvikas\OnePageCheckout\Integration\CheckoutCapabilityDetector;
use Jzvikas\OnePageCheckout\Integration\CheckoutIntegrationStrategy;
use Jzvikas\OnePageCheckout\Integration\PrestaShopRuntimeProbe;

$shopRoot = $argv[1] ?? '';
$expectedFamily = $argv[2] ?? '';
$expectNativeOpc = ($argv[3] ?? '0') === '1';

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

if ($shopRoot === '' || !is_file($shopRoot . '/config/config.inc.php')) {
    $fail('Installed PrestaShop root is missing or invalid.');
}

if (!in_array($expectedFamily, ['9.0', '9.1', '9.2'], true)) {
    $fail('Expected runtime family must be 9.0, 9.1 or 9.2.');
}

require_once $shopRoot . '/config/config.inc.php';

$modulePath = $shopRoot . '/modules/jzonepagecheckout/jzonepagecheckout.php';
if (!is_file($modulePath)) {
    $fail('jzonepagecheckout module is not mounted in the installed shop.');
}
require_once $modulePath;

if (!Module::isInstalled('jzonepagecheckout')) {
    $fail('jzonepagecheckout is not installed in the runtime shop.');
}
if (!Module::isEnabled('jzonepagecheckout')) {
    $fail('jzonepagecheckout is not enabled in the runtime shop.');
}

$module = Module::getInstanceByName('jzonepagecheckout');
if (!$module instanceof JzOnePageCheckout) {
    $fail('Unable to load the installed JzOnePageCheckout module instance.');
}
if (version_compare((string) $module->version, '0.4.0', '<')) {
    $fail(sprintf('Installed module version %s predates the finalization schema baseline 0.4.0.', (string) $module->version));
}

$detector = new CheckoutCapabilityDetector(new PrestaShopRuntimeProbe());
$capabilities = $detector->detect();

if (!str_starts_with($capabilities->prestashopVersion, $expectedFamily . '.')) {
    $fail(sprintf(
        'Expected PrestaShop family %s, got %s.',
        $expectedFamily,
        $capabilities->prestashopVersion,
    ));
}

if (in_array($expectedFamily, ['9.0', '9.1'], true)) {
    if ($capabilities->strategy !== CheckoutIntegrationStrategy::CheckoutRenderHook) {
        $fail(sprintf('PrestaShop %s must resolve the checkout-render integration strategy.', $expectedFamily));
    }
    if (!$module->isRegisteredInHook('actionCheckoutRender')) {
        $fail(sprintf('PrestaShop %s installation did not register actionCheckoutRender.', $expectedFamily));
    }
    if ($module->isRegisteredInHook('actionCheckoutBuildProcess')) {
        $fail(sprintf('PrestaShop %s installation must not register actionCheckoutBuildProcess.', $expectedFamily));
    }
    if (interface_exists('PrestaShop\\PrestaShop\\Adapter\\Order\\Checkout\\CheckoutProcessProviderInterface')) {
        $fail(sprintf('PrestaShop %s unexpectedly exposes the 9.2 checkout provider interface.', $expectedFamily));
    }
} else {
    if ($capabilities->strategy !== CheckoutIntegrationStrategy::ProviderHook) {
        $fail('PrestaShop 9.2 must resolve the provider-hook integration strategy.');
    }
    if (!$module->isRegisteredInHook('actionCheckoutBuildProcess')) {
        $fail('PrestaShop 9.2 installation did not register actionCheckoutBuildProcess.');
    }
    if ($module->isRegisteredInHook('actionCheckoutRender')) {
        $fail('PrestaShop 9.2 installation must not register actionCheckoutRender.');
    }
    if (!interface_exists('PrestaShop\\PrestaShop\\Adapter\\Order\\Checkout\\CheckoutProcessProviderInterface')) {
        $fail('PrestaShop 9.2 checkout provider interface is unavailable at runtime.');
    }
}

if (!$module->isRegisteredInHook('actionFrontControllerSetMedia')) {
    $fail('The frontend media hook required by the current checkout shell is not registered.');
}
if (!$module->isRegisteredInHook('actionValidateOrderAfter')) {
    $fail('The successful-order cleanup hook required by finalization lifecycle is not registered.');
}

if ($capabilities->nativeOnePageCheckoutInstalled !== $expectNativeOpc) {
    $fail(sprintf(
        'Native OPC installation expectation mismatch: expected=%s actual=%s.',
        $expectNativeOpc ? 'true' : 'false',
        $capabilities->nativeOnePageCheckoutInstalled ? 'true' : 'false',
    ));
}
if ($expectNativeOpc && !$capabilities->nativeOnePageCheckoutEnabled) {
    $fail('Native ps_onepagecheckout was expected to be enabled for the conflict test.');
}

Configuration::updateValue(JzOnePageCheckout::CONFIG_CHECKOUT_ENABLED, true);
if ($module->isCustomCheckoutActive()) {
    $fail('Integration readiness is intentionally closed; runtime checkout activation must fail closed.');
}
Configuration::updateValue(JzOnePageCheckout::CONFIG_CHECKOUT_ENABLED, false);

fwrite(STDOUT, sprintf(
    "Runtime contract OK: PrestaShop %s, module=%s, strategy=%s, nativeOpcInstalled=%s, nativeOpcEnabled=%s\n",
    $capabilities->prestashopVersion,
    (string) $module->version,
    $capabilities->strategy->value,
    $capabilities->nativeOnePageCheckoutInstalled ? 'yes' : 'no',
    $capabilities->nativeOnePageCheckoutEnabled ? 'yes' : 'no',
));

<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Jzvikas\OnePageCheckout\Integration\CheckoutActivationBlockReason;
use Jzvikas\OnePageCheckout\Integration\CheckoutActivationPolicy;
use Jzvikas\OnePageCheckout\Integration\CheckoutHookPlan;
use Jzvikas\OnePageCheckout\Integration\CheckoutIntegrationStrategy;
use Jzvikas\OnePageCheckout\Integration\CheckoutRuntimeCapabilities;

$assertSame = static function (mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        fwrite(STDERR, sprintf("%s\nExpected: %s\nActual: %s\n", $message, var_export($expected, true), var_export($actual, true)));
        exit(1);
    }
};

$assertSame(
    ['actionCheckoutRender'],
    CheckoutHookPlan::forPrestaShopVersion('9.1.5')->hooks,
    'PrestaShop 9.1 must register only the legacy checkout render hook.'
);
$assertSame(
    ['actionCheckoutBuildProcess'],
    CheckoutHookPlan::forPrestaShopVersion('9.2.0')->hooks,
    'PrestaShop 9.2 must register only the provider hook.'
);
$assertSame([], CheckoutHookPlan::forPrestaShopVersion('8.2.0')->hooks, 'Unsupported versions must not register checkout hooks.');
$assertSame([], CheckoutHookPlan::forPrestaShopVersion('10.0.0')->hooks, 'Future unsupported major versions must fail closed.');

$policy = new CheckoutActivationPolicy();
$capabilities = static function (
    CheckoutIntegrationStrategy $strategy,
    bool $nativeEnabled = false,
): CheckoutRuntimeCapabilities {
    return new CheckoutRuntimeCapabilities(
        prestashopVersion: $strategy === CheckoutIntegrationStrategy::ProviderHook ? '9.2.0' : '9.1.5',
        strategy: $strategy,
        providerInterfaceAvailable: $strategy === CheckoutIntegrationStrategy::ProviderHook,
        providerHookAvailable: $strategy === CheckoutIntegrationStrategy::ProviderHook,
        checkoutRenderHookAvailable: $strategy === CheckoutIntegrationStrategy::CheckoutRenderHook,
        nativeOnePageCheckoutInstalled: $nativeEnabled,
        nativeOnePageCheckoutEnabled: $nativeEnabled,
    );
};

$decision = $policy->decide($capabilities(CheckoutIntegrationStrategy::Unsupported), true, true);
$assertSame(false, $decision->allowed, 'Unsupported runtimes must fail closed.');
$assertSame(CheckoutActivationBlockReason::UnsupportedRuntime, $decision->blockReason, 'Unsupported runtime reason mismatch.');

$decision = $policy->decide($capabilities(CheckoutIntegrationStrategy::ProviderHook, true), true, true);
$assertSame(false, $decision->allowed, 'Native PrestaShop OPC must block a competing provider.');
$assertSame(CheckoutActivationBlockReason::NativeProviderConflict, $decision->blockReason, 'Native provider conflict reason mismatch.');

$decision = $policy->decide($capabilities(CheckoutIntegrationStrategy::CheckoutRenderHook), false, true);
$assertSame(false, $decision->allowed, 'Disabled checkout feature must not take over checkout.');
$assertSame(CheckoutActivationBlockReason::FeatureDisabled, $decision->blockReason, 'Disabled feature reason mismatch.');

$decision = $policy->decide($capabilities(CheckoutIntegrationStrategy::CheckoutRenderHook), true, false);
$assertSame(false, $decision->allowed, 'Incomplete integration shell must fail closed.');
$assertSame(CheckoutActivationBlockReason::IntegrationShellNotReady, $decision->blockReason, 'Shell readiness reason mismatch.');

$decision = $policy->decide($capabilities(CheckoutIntegrationStrategy::CheckoutRenderHook), true, true);
$assertSame(true, $decision->allowed, 'A supported, conflict-free and ready integration must be activatable.');
$assertSame(null, $decision->blockReason, 'Allowed activation must not carry a block reason.');

$frontServicesPath = dirname(__DIR__, 2) . '/config/front/services.yml';
$frontServices = is_file($frontServicesPath) ? file_get_contents($frontServicesPath) : false;
$assertSame(true, is_string($frontServices), 'The PrestaShop front-office service configuration must exist.');
$assertSame(
    true,
    is_string($frontServices) && str_contains($frontServices, '../services.yml'),
    'The front-office service configuration must import the shared module service graph.'
);

echo "Checkout integration shell smoke tests passed.\n";

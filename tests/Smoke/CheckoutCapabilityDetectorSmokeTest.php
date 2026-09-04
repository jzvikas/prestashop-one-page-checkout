<?php

declare(strict_types=1);

use Jzvikas\OnePageCheckout\Integration\CheckoutCapabilityDetector;
use Jzvikas\OnePageCheckout\Integration\CheckoutIntegrationStrategy;
use Jzvikas\OnePageCheckout\Integration\RuntimeProbeInterface;

require dirname(__DIR__) . '/bootstrap.php';

final class FakeRuntimeProbe implements RuntimeProbeInterface
{
    public function __construct(
        private readonly string $version,
        private readonly bool $providerInterface,
        private readonly bool $providerHook,
        private readonly bool $renderHook,
        private readonly bool $nativeInstalled = false,
        private readonly bool $nativeEnabled = false,
    ) {
    }

    public function prestashopVersion(): string
    {
        return $this->version;
    }

    public function interfaceExists(string $interface): bool
    {
        return $this->providerInterface;
    }

    public function hookExists(string $hookName): bool
    {
        return match ($hookName) {
            'actionCheckoutBuildProcess' => $this->providerHook,
            'actionCheckoutRender' => $this->renderHook,
            default => false,
        };
    }

    public function moduleIsInstalled(string $moduleName): bool
    {
        return $moduleName === 'ps_onepagecheckout' && $this->nativeInstalled;
    }

    public function moduleIsEnabled(string $moduleName): bool
    {
        return $moduleName === 'ps_onepagecheckout' && $this->nativeEnabled;
    }
}

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, sprintf("FAIL: %s\nExpected: %s\nActual: %s\n", $message, var_export($expected, true), var_export($actual, true)));
        exit(1);
    }
}

$legacy = (new CheckoutCapabilityDetector(new FakeRuntimeProbe('9.1.5', false, false, true)))->detect();
assertSameValue(CheckoutIntegrationStrategy::CheckoutRenderHook, $legacy->strategy, 'PrestaShop 9.1 must select actionCheckoutRender strategy.');
assertSameValue(true, $legacy->canActivateCustomCheckout(), 'Legacy strategy should be activatable when no conflict exists.');

$provider = (new CheckoutCapabilityDetector(new FakeRuntimeProbe('9.2.0', true, true, true)))->detect();
assertSameValue(CheckoutIntegrationStrategy::ProviderHook, $provider->strategy, 'PrestaShop 9.2 must select provider strategy when the interface and hook exist.');
assertSameValue(true, $provider->canActivateCustomCheckout(), 'Provider strategy should be activatable when native OPC is disabled.');

$conflict = (new CheckoutCapabilityDetector(new FakeRuntimeProbe('9.2.0', true, true, true, true, true)))->detect();
assertSameValue(true, $conflict->hasNativeProviderConflict(), 'Enabled native OPC must be detected as a provider conflict.');
assertSameValue(false, $conflict->canActivateCustomCheckout(), 'Custom provider must not activate while native OPC is enabled.');

$guarded = (new CheckoutCapabilityDetector(new FakeRuntimeProbe('9.2.0', false, true, true)))->detect();
assertSameValue(CheckoutIntegrationStrategy::Unsupported, $guarded->strategy, 'Missing 9.2 provider interface must prevent blind provider activation.');

$unsupported = (new CheckoutCapabilityDetector(new FakeRuntimeProbe('8.2.0', false, false, true)))->detect();
assertSameValue(CheckoutIntegrationStrategy::Unsupported, $unsupported->strategy, 'PrestaShop before 9.0 is unsupported.');

fwrite(STDOUT, "Checkout capability smoke tests passed.\n");

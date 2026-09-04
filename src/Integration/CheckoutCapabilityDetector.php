<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Integration;

final readonly class CheckoutCapabilityDetector
{
    private const PROVIDER_INTERFACE = 'PrestaShop\\PrestaShop\\Adapter\\Order\\Checkout\\CheckoutProcessProviderInterface';
    private const PROVIDER_HOOK = 'actionCheckoutBuildProcess';
    private const LEGACY_RENDER_HOOK = 'actionCheckoutRender';
    private const NATIVE_OPC_MODULE = 'ps_onepagecheckout';

    public function __construct(private RuntimeProbeInterface $runtimeProbe)
    {
    }

    public function detect(): CheckoutRuntimeCapabilities
    {
        $version = $this->runtimeProbe->prestashopVersion();
        $providerInterfaceAvailable = $this->runtimeProbe->interfaceExists(self::PROVIDER_INTERFACE);
        $providerHookAvailable = $this->runtimeProbe->hookExists(self::PROVIDER_HOOK);
        $checkoutRenderHookAvailable = $this->runtimeProbe->hookExists(self::LEGACY_RENDER_HOOK);

        $strategy = $this->resolveStrategy(
            $version,
            $providerInterfaceAvailable,
            $providerHookAvailable,
            $checkoutRenderHookAvailable,
        );

        return new CheckoutRuntimeCapabilities(
            prestashopVersion: $version,
            strategy: $strategy,
            providerInterfaceAvailable: $providerInterfaceAvailable,
            providerHookAvailable: $providerHookAvailable,
            checkoutRenderHookAvailable: $checkoutRenderHookAvailable,
            nativeOnePageCheckoutInstalled: $this->runtimeProbe->moduleIsInstalled(self::NATIVE_OPC_MODULE),
            nativeOnePageCheckoutEnabled: $this->runtimeProbe->moduleIsEnabled(self::NATIVE_OPC_MODULE),
        );
    }

    private function resolveStrategy(
        string $version,
        bool $providerInterfaceAvailable,
        bool $providerHookAvailable,
        bool $checkoutRenderHookAvailable,
    ): CheckoutIntegrationStrategy {
        if (version_compare($version, '9.0.0', '<') || version_compare($version, '10.0.0', '>=')) {
            return CheckoutIntegrationStrategy::Unsupported;
        }

        if (
            version_compare($version, '9.2.0', '>=')
            && $providerInterfaceAvailable
            && $providerHookAvailable
        ) {
            return CheckoutIntegrationStrategy::ProviderHook;
        }

        if (
            version_compare($version, '9.2.0', '<')
            && $checkoutRenderHookAvailable
        ) {
            return CheckoutIntegrationStrategy::CheckoutRenderHook;
        }

        return CheckoutIntegrationStrategy::Unsupported;
    }
}

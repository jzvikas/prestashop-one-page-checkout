<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Integration;

final readonly class CheckoutRuntimeCapabilities
{
    public function __construct(
        public string $prestashopVersion,
        public CheckoutIntegrationStrategy $strategy,
        public bool $providerInterfaceAvailable,
        public bool $providerHookAvailable,
        public bool $checkoutRenderHookAvailable,
        public bool $nativeOnePageCheckoutInstalled,
        public bool $nativeOnePageCheckoutEnabled,
    ) {
    }

    public function hasNativeProviderConflict(): bool
    {
        return $this->strategy === CheckoutIntegrationStrategy::ProviderHook
            && $this->nativeOnePageCheckoutEnabled;
    }

    public function canActivateCustomCheckout(): bool
    {
        return $this->strategy !== CheckoutIntegrationStrategy::Unsupported
            && !$this->hasNativeProviderConflict();
    }
}

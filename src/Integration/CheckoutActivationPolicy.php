<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Integration;

final readonly class CheckoutActivationPolicy
{
    public function decide(
        CheckoutRuntimeCapabilities $capabilities,
        bool $featureEnabled,
        bool $integrationShellReady,
    ): CheckoutActivationDecision {
        if ($capabilities->strategy === CheckoutIntegrationStrategy::Unsupported) {
            return CheckoutActivationDecision::block(CheckoutActivationBlockReason::UnsupportedRuntime);
        }

        if ($capabilities->hasNativeProviderConflict()) {
            return CheckoutActivationDecision::block(CheckoutActivationBlockReason::NativeProviderConflict);
        }

        if (!$featureEnabled) {
            return CheckoutActivationDecision::block(CheckoutActivationBlockReason::FeatureDisabled);
        }

        if (!$integrationShellReady) {
            return CheckoutActivationDecision::block(CheckoutActivationBlockReason::IntegrationShellNotReady);
        }

        return CheckoutActivationDecision::allow();
    }
}

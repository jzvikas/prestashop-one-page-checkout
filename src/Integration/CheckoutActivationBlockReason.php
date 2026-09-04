<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Integration;

enum CheckoutActivationBlockReason: string
{
    case UnsupportedRuntime = 'unsupported_runtime';
    case NativeProviderConflict = 'native_provider_conflict';
    case FeatureDisabled = 'feature_disabled';
    case IntegrationShellNotReady = 'integration_shell_not_ready';
}

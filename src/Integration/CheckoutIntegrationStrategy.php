<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Integration;

enum CheckoutIntegrationStrategy: string
{
    case ProviderHook = 'provider_hook';
    case CheckoutRenderHook = 'checkout_render_hook';
    case Unsupported = 'unsupported';
}

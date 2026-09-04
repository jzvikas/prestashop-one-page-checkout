<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout;

use JsonException;

final readonly class CheckoutStateVersioner
{
    private const VERSION_PREFIX = 'v1:';

    /** @throws JsonException */
    public function version(CheckoutState $state): string
    {
        $encoded = json_encode(
            $state->versionPayload(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return self::VERSION_PREFIX . hash('sha256', $encoded);
    }
}

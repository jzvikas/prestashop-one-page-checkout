<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Rendering;

interface CheckoutSessionProviderInterface
{
    /**
     * Return the active Core CheckoutSession-compatible object for the current checkout request.
     *
     * The adapter deliberately uses an object return type so PrestaShop 9.0/9.1 code can load
     * without introducing a hard dependency on a version-specific namespaced contract.
     */
    public function get(\Context $context): object;
}

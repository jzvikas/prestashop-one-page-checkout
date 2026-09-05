<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Carrier;

final readonly class CheckoutCarrierSelection
{
    public function __construct(
        public string $deliveryOption,
    ) {
    }
}

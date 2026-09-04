<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Rendering;

interface CheckoutDeliveryOptionsPresenterInterface
{
    /**
     * @return array{
     *   isVirtual: bool,
     *   deliveryOptions: array<string, array<string, mixed>>,
     *   selectedDeliveryOption: ?string,
     *   hookDisplayBeforeCarrier: string,
     *   hookDisplayAfterCarrier: string
     * }
     */
    public function present(\Context $context): array;
}

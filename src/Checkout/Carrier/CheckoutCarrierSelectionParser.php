<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Carrier;

final class CheckoutCarrierSelectionParser
{
    private const MAX_DELIVERY_OPTION_LENGTH = 255;

    /** @param array<string,mixed> $request */
    public function parse(array $request): CheckoutCarrierSelection
    {
        $deliveryOption = $request['deliveryOption'] ?? null;
        if (!is_string($deliveryOption)
            || $deliveryOption === ''
            || strlen($deliveryOption) > self::MAX_DELIVERY_OPTION_LENGTH
            || preg_match('/^(?:[1-9][0-9]*,)+$/D', $deliveryOption) !== 1) {
            throw new CheckoutCarrierSelectionException('deliveryOption has an invalid format.');
        }

        return new CheckoutCarrierSelection($deliveryOption);
    }
}

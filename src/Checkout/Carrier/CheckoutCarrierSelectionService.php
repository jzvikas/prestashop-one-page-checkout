<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Carrier;

use Jzvikas\OnePageCheckout\Checkout\Rendering\CheckoutSessionProviderInterface;
use RuntimeException;

final readonly class CheckoutCarrierSelectionService
{
    public function __construct(
        private CheckoutSessionProviderInterface $checkoutSessionProvider,
    ) {
    }

    /** @return bool true when the selected option changed */
    public function apply(\Context $context, CheckoutCarrierSelection $requestedSelection): bool
    {
        $cart = $context->cart ?? null;
        if (!$cart instanceof \Cart || (int) ($cart->id ?? 0) <= 0) {
            throw new RuntimeException('A loaded cart is required to select a delivery option.');
        }
        if ($cart->isVirtualCart()) {
            throw new CheckoutCarrierSelectionException('Virtual carts do not accept a delivery option.');
        }

        $checkoutSession = $this->checkoutSessionProvider->get($context);
        if (!method_exists($checkoutSession, 'getDeliveryOptions') || !method_exists($checkoutSession, 'getSelectedDeliveryOption') || !method_exists($checkoutSession, 'setDeliveryOption')) {
            throw new RuntimeException('The Core checkout session does not expose delivery selection methods.');
        }

        $deliveryOptions = $checkoutSession->getDeliveryOptions();
        if (!is_array($deliveryOptions)) {
            throw new RuntimeException('Core delivery options must be an array.');
        }

        $canonicalOption = null;
        foreach (array_keys($deliveryOptions) as $availableOption) {
            if (!is_string($availableOption) && !is_int($availableOption)) {
                continue;
            }
            $availableOption = (string) $availableOption;
            if (hash_equals($availableOption, $requestedSelection->deliveryOption)) {
                $canonicalOption = $availableOption;
                break;
            }
        }

        if ($canonicalOption === null) {
            throw new CheckoutCarrierSelectionException('The selected delivery option is no longer available.');
        }

        $selectedOption = $checkoutSession->getSelectedDeliveryOption();
        $selectedOption = is_string($selectedOption) || is_int($selectedOption) ? (string) $selectedOption : null;
        if ($selectedOption !== null && hash_equals($selectedOption, $canonicalOption)) {
            return false;
        }

        if ($checkoutSession->setDeliveryOption($canonicalOption) !== true) {
            throw new RuntimeException('PrestaShop could not persist the selected delivery option.');
        }

        return true;
    }
}

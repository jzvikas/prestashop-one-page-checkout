<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Rendering;

final class PrestaShopCheckoutDeliveryOptionsPresenter implements CheckoutDeliveryOptionsPresenterInterface
{
    public function present(\Context $context): array
    {
        $cart = $context->cart ?? null;
        if (!$cart instanceof \Cart || (int) $cart->id <= 0) {
            throw new \RuntimeException('A loaded cart is required to present delivery options.');
        }

        if ($cart->isVirtualCart()) {
            return [
                'isVirtual' => true,
                'deliveryOptions' => [],
                'selectedDeliveryOption' => null,
                'hookDisplayBeforeCarrier' => '',
                'hookDisplayAfterCarrier' => '',
            ];
        }

        $controller = $context->controller ?? null;
        if (!is_object($controller) || !method_exists($controller, 'getCheckoutSession')) {
            throw new \RuntimeException('The active checkout controller does not expose a Core CheckoutSession.');
        }

        // Native CheckoutDeliveryStep executes this lifecycle hook before rendering carriers.
        \Hook::exec('actionCarrierProcess', ['cart' => $cart]);

        $checkoutSession = $controller->getCheckoutSession();
        if (!is_object($checkoutSession)
            || !method_exists($checkoutSession, 'getDeliveryOptions')
            || !method_exists($checkoutSession, 'getSelectedDeliveryOption')) {
            throw new \RuntimeException('The Core checkout session does not expose delivery options.');
        }

        $deliveryOptions = $checkoutSession->getDeliveryOptions();
        if (!is_array($deliveryOptions)) {
            throw new \RuntimeException('Core delivery options must be an array.');
        }

        $normalizedOptions = [];
        foreach ($deliveryOptions as $deliveryOption => $carrier) {
            if (!is_string($deliveryOption) && !is_int($deliveryOption)) {
                continue;
            }
            if (!is_array($carrier)) {
                continue;
            }

            $normalizedOptions[(string) $deliveryOption] = $carrier;
        }

        $selectedDeliveryOption = $checkoutSession->getSelectedDeliveryOption();
        $selectedDeliveryOption = is_string($selectedDeliveryOption) || is_int($selectedDeliveryOption)
            ? (string) $selectedDeliveryOption
            : null;

        return [
            'isVirtual' => false,
            'deliveryOptions' => $normalizedOptions,
            'selectedDeliveryOption' => $selectedDeliveryOption,
            // These are trusted module-hook HTML boundaries. The Smarty template renders only these values unescaped.
            'hookDisplayBeforeCarrier' => (string) \Hook::exec('displayBeforeCarrier', ['cart' => $cart]),
            'hookDisplayAfterCarrier' => (string) \Hook::exec('displayAfterCarrier', ['cart' => $cart]),
        ];
    }
}

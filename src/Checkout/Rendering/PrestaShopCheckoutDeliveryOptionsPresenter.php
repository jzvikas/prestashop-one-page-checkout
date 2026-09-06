<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Rendering;

final class PrestaShopCheckoutDeliveryOptionsPresenter implements CheckoutDeliveryOptionsPresenterInterface
{
    public function __construct(
        private CheckoutSessionProviderInterface $checkoutSessionProvider,
    ) {
    }

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

        // Native CheckoutDeliveryStep executes this lifecycle hook before rendering carriers.
        \Hook::exec('actionCarrierProcess', ['cart' => $cart]);

        // Core Cart::getDeliveryOptionList() keeps a request-local static cache keyed by cart ID.
        // A checkout request can prime that cache before a just-persisted address mutation changes
        // delivery eligibility. Core itself uses flush=true where a fresh delivery-option decision
        // is required (for example Cart::setDeliveryOption()). Refresh that same Core cache here,
        // after carrier hooks and before CheckoutSession/DeliveryOptionsFinder presents it. We do
        // not consume this raw list, select a carrier, or bypass Core presentation semantics.
        $freshDeliveryOptionList = $cart->getDeliveryOptionList(null, true);
        if (!is_array($freshDeliveryOptionList)) {
            throw new \RuntimeException('The Core cart delivery option list is unavailable.');
        }

        $checkoutSession = $this->checkoutSessionProvider->get($context);
        if (!method_exists($checkoutSession, 'getDeliveryOptions')
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

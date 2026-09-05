<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Rendering;

final class PrestaShopCheckoutPaymentOptionsPresenter implements CheckoutPaymentOptionsPresenterInterface
{
    public function present(\Context $context): array
    {
        $cart = $context->cart ?? null;
        if (!$cart instanceof \Cart || (int) $cart->id <= 0) {
            throw new \RuntimeException('A loaded cart is required to present payment options.');
        }

        if (!class_exists(\PaymentOptionsFinder::class)) {
            throw new \RuntimeException('PrestaShop PaymentOptionsFinder is not available.');
        }

        $isFree = 0.0 === (float) $cart->getOrderTotal(true, \Cart::BOTH);
        $paymentOptions = (new \PaymentOptionsFinder())->present($isFree);
        if (!is_array($paymentOptions)) {
            throw new \RuntimeException('Core payment options must be an array.');
        }

        $normalized = [];
        foreach ($paymentOptions as $moduleName => $moduleOptions) {
            if (!is_array($moduleOptions)) {
                continue;
            }

            $options = [];
            foreach ($moduleOptions as $option) {
                if (!is_array($option) || empty($option['id'])) {
                    continue;
                }
                $options[] = $option;
            }

            if ($options !== []) {
                $normalized[$moduleName] = $options;
            }
        }

        return [
            'isFree' => $isFree,
            'paymentOptions' => $normalized,
            // Payment module output is an explicit trusted hook-HTML boundary.
            'hookDisplayPaymentTop' => (string) \Hook::exec('displayPaymentTop'),
        ];
    }
}

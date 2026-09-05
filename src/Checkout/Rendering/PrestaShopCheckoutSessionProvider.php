<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Rendering;

final class PrestaShopCheckoutSessionProvider implements CheckoutSessionProviderInterface
{
    public function get(\Context $context): object
    {
        $controller = $context->controller ?? null;
        if (!is_object($controller) || !method_exists($controller, 'getCheckoutSession')) {
            throw new \RuntimeException('The active checkout controller does not expose a Core CheckoutSession.');
        }

        $session = $controller->getCheckoutSession();
        if (!is_object($session)) {
            throw new \RuntimeException('The active checkout controller returned an invalid Core CheckoutSession.');
        }

        return $session;
    }
}

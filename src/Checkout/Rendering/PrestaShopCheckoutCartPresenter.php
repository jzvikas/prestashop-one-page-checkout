<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Rendering;

use RuntimeException;

final readonly class PrestaShopCheckoutCartPresenter implements CheckoutCartPresenterInterface
{
    public function present(\Context $context): mixed
    {
        $cart = $context->cart ?? null;
        if (!$cart instanceof \Cart || (int) ($cart->id ?? 0) <= 0) {
            throw new RuntimeException('Cannot present checkout summary without a loaded cart.');
        }

        $cart->resetProductRelatedStaticCache();
        \Cache::clean('presentedCart_*');

        $presented = (new \PrestaShop\PrestaShop\Adapter\Presenter\Cart\CartPresenter())->present($cart, true);

        if ($presented instanceof \ArrayAccess) {
            $presented->offsetGet('products');
            $presented->offsetGet('subtotals');
            $presented->offsetGet('totals');
            $presented->offsetGet('products_count');
        }

        return $presented;
    }
}

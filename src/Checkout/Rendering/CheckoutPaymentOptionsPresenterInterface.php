<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Rendering;

interface CheckoutPaymentOptionsPresenterInterface
{
    /**
     * @return array{
     *   isFree: bool,
     *   paymentOptions: array<string|int, array<int, array<string, mixed>>>,
     *   hookDisplayPaymentTop: string
     * }
     */
    public function present(\Context $context): array;
}

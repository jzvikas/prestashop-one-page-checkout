<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Rendering;

use Jzvikas\OnePageCheckout\Checkout\CheckoutSection;

interface CheckoutSectionRendererInterface
{
    public function section(): CheckoutSection;

    public function render(\Context $context): string;
}

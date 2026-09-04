<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Rendering;

interface CheckoutCartPresenterInterface
{
    public function present(\Context $context): mixed;
}

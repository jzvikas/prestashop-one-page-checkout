<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Rendering;

use Jzvikas\OnePageCheckout\Checkout\CheckoutServerSelections;

interface CheckoutStateAwareSectionRendererInterface extends CheckoutSectionRendererInterface
{
    public function renderWithSelections(\Context $context, CheckoutServerSelections $selections): string;
}

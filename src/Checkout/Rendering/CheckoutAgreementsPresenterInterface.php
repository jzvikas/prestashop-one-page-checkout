<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Rendering;

interface CheckoutAgreementsPresenterInterface
{
    /** @return array{conditions: array<string, string>} */
    public function present(\Context $context): array;
}

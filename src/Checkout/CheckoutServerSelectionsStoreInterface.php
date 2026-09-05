<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout;

interface CheckoutServerSelectionsStoreInterface
{
    public function load(\Context $context): CheckoutServerSelections;

    public function save(\Context $context, CheckoutServerSelections $selections): void;

    public function delete(\Context $context): void;
}

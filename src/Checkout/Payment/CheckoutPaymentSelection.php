<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Payment;

final readonly class CheckoutPaymentSelection
{
    public function __construct(
        public string $optionId,
        public string $moduleName,
    ) {
    }

    public function stateKey(): string
    {
        return $this->moduleName . ':' . $this->optionId;
    }
}

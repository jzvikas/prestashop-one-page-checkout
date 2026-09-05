<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Address;

final class CheckoutAddressFormException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?string $field = null,
    ) {
        parent::__construct($message);
    }
}

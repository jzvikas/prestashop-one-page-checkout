<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Address;

final readonly class CheckoutAddressFormSubmission
{
    private function __construct(
        public bool $saved,
        public ?int $addressId,
        public string $formHtml,
    ) {
    }

    public static function saved(int $addressId, string $formHtml): self
    {
        return new self(true, $addressId, $formHtml);
    }

    public static function invalid(string $formHtml): self
    {
        return new self(false, null, $formHtml);
    }
}

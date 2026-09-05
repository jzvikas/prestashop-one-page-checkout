<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Address;

final readonly class CheckoutAddressFormSubmission
{
    /**
     * @param array<string,mixed> $form
     */
    private function __construct(
        public bool $saved,
        public ?int $addressId,
        public array $form,
    ) {
    }

    /** @param array<string,mixed> $form */
    public static function saved(int $addressId, array $form): self
    {
        return new self(true, $addressId, $form);
    }

    /** @param array<string,mixed> $form */
    public static function invalid(array $form): self
    {
        return new self(false, null, $form);
    }
}

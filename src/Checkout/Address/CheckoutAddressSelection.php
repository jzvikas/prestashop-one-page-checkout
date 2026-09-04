<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Address;

use InvalidArgumentException;

final readonly class CheckoutAddressSelection
{
    public function __construct(
        public ?int $deliveryAddressId,
        public ?int $invoiceAddressId,
        public bool $useSameAddress,
    ) {
        if ($deliveryAddressId !== null && $deliveryAddressId <= 0) {
            throw new InvalidArgumentException('deliveryAddressId must be null or positive.');
        }
        if ($invoiceAddressId !== null && $invoiceAddressId <= 0) {
            throw new InvalidArgumentException('invoiceAddressId must be null or positive.');
        }
        if ($useSameAddress && $invoiceAddressId !== null) {
            throw new InvalidArgumentException('invoiceAddressId must be omitted when useSameAddress is true.');
        }
        if (!$useSameAddress && $invoiceAddressId === null) {
            throw new InvalidArgumentException('invoiceAddressId is required when useSameAddress is false.');
        }
    }
}

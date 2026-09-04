<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Rendering;

interface CheckoutAddressBookPresenterInterface
{
    /**
     * @return array{
     *     addresses: list<array{id: int, alias: string, lines: list<string>}>,
     *     deliveryAddressId: int|null,
     *     invoiceAddressId: int|null,
     *     useSameAddress: bool
     * }
     */
    public function present(\Context $context): array;
}

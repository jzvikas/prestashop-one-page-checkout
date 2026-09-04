<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout;

enum CheckoutSection: string
{
    case Identity = 'identity';
    case Addresses = 'addresses';
    case Delivery = 'delivery';
    case Payment = 'payment';
    case Agreements = 'agreements';
    case Summary = 'summary';

    /** @return list<self> */
    public static function ordered(): array
    {
        return [
            self::Identity,
            self::Addresses,
            self::Delivery,
            self::Payment,
            self::Agreements,
            self::Summary,
        ];
    }
}

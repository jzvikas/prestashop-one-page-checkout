<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Finalization;

enum CheckoutFinalizationPreflightReason: string
{
    case CartEmpty = 'cart_empty';
    case CartNotOrderable = 'cart_not_orderable';
    case MinimumPurchaseRequired = 'minimum_purchase_required';
    case CustomerRequired = 'customer_required';
    case AddressInvalid = 'address_invalid';
    case CarrierInvalid = 'carrier_invalid';
    case PaymentInvalid = 'payment_invalid';
    case AgreementsInvalid = 'agreements_invalid';
    case OrderAlreadyExists = 'order_already_exists';
}

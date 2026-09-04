<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Security;

enum CheckoutMutationBlockReason: string
{
    case InvalidCsrf = 'invalid_csrf';
    case MissingCart = 'missing_cart';
    case InvalidCartBinding = 'invalid_cart_binding';
    case CrossCart = 'cross_cart';
    case CustomerMismatch = 'customer_mismatch';
    case StaleState = 'stale_state';
}

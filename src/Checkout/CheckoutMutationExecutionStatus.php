<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout;

enum CheckoutMutationExecutionStatus: string
{
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Busy = 'busy';
}

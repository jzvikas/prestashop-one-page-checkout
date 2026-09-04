<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Concurrency;

use RuntimeException;
use Throwable;

final class CheckoutCartLockUnavailable extends RuntimeException
{
    public function __construct(
        public readonly int $cartId,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('Checkout cart %d is busy or could not be locked.', $cartId),
            0,
            $previous,
        );
    }
}

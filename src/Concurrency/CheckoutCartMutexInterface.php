<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Concurrency;

use Closure;

interface CheckoutCartMutexInterface
{
    public function synchronized(int $cartId, Closure $criticalSection): mixed;
}

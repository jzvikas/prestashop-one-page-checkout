<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Finalization;

use RuntimeException;
use Throwable;

final class CheckoutFinalizationPreflightException extends RuntimeException
{
    public function __construct(
        public readonly CheckoutFinalizationPreflightReason $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct($reason->value, 0, $previous);
    }
}

<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Security;

use Jzvikas\OnePageCheckout\Checkout\CheckoutState;
use LogicException;

final readonly class CheckoutMutationGuardResult
{
    private function __construct(
        public bool $allowed,
        public ?CheckoutMutationBlockReason $reason,
        public ?CheckoutState $currentState,
    ) {
        if ($allowed === ($reason !== null)) {
            throw new LogicException('Allowed mutation guard results cannot have a block reason and blocked results must have one.');
        }
    }

    public static function allow(CheckoutState $currentState): self
    {
        return new self(true, null, $currentState);
    }

    public static function block(
        CheckoutMutationBlockReason $reason,
        ?CheckoutState $currentState = null,
    ): self {
        return new self(false, $reason, $currentState);
    }
}

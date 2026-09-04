<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Integration;

final readonly class CheckoutActivationDecision
{
    private function __construct(
        public bool $allowed,
        public ?CheckoutActivationBlockReason $blockReason,
    ) {
    }

    public static function allow(): self
    {
        return new self(true, null);
    }

    public static function block(CheckoutActivationBlockReason $reason): self
    {
        return new self(false, $reason);
    }
}

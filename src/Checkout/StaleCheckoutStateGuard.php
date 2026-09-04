<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout;

final readonly class StaleCheckoutStateGuard
{
    public function __construct(private CheckoutStateVersioner $versioner)
    {
    }

    public function matches(?string $clientStateVersion, CheckoutState $currentState): bool
    {
        if ($clientStateVersion === null || $clientStateVersion === '') {
            return false;
        }

        return hash_equals($this->versioner->version($currentState), $clientStateVersion);
    }
}

<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Finalization;

interface CheckoutFinalizationReservationStoreInterface
{
    public function acquire(\Context $context, string $stateVersion, string $paymentSelection, string $attemptId): void;

    public function isActive(\Context $context): bool;

    public function releaseAttempt(\Context $context, string $attemptId): void;

    public function clear(\Context $context): void;
}

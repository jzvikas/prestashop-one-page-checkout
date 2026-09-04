<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout;

use Jzvikas\OnePageCheckout\Security\CheckoutMutationBlockReason;
use LogicException;

final readonly class CheckoutMutationExecutionResult
{
    private function __construct(
        public CheckoutMutationExecutionStatus $status,
        public ?CheckoutRefreshResult $refreshResult,
        public ?CheckoutMutationBlockReason $blockReason,
        public ?CheckoutState $currentState,
    ) {
        match ($status) {
            CheckoutMutationExecutionStatus::Completed => $this->assertCompleted(),
            CheckoutMutationExecutionStatus::Rejected => $this->assertRejected(),
            CheckoutMutationExecutionStatus::Busy => $this->assertBusy(),
        };
    }

    public static function completed(CheckoutRefreshResult $refreshResult): self
    {
        return new self(CheckoutMutationExecutionStatus::Completed, $refreshResult, null, null);
    }

    public static function rejected(
        CheckoutMutationBlockReason $reason,
        ?CheckoutState $currentState = null,
    ): self {
        return new self(CheckoutMutationExecutionStatus::Rejected, null, $reason, $currentState);
    }

    public static function busy(): self
    {
        return new self(CheckoutMutationExecutionStatus::Busy, null, null, null);
    }

    private function assertCompleted(): void
    {
        if ($this->refreshResult === null || $this->blockReason !== null || $this->currentState !== null) {
            throw new LogicException('Completed mutation result has invalid payload.');
        }
    }

    private function assertRejected(): void
    {
        if ($this->refreshResult !== null || $this->blockReason === null) {
            throw new LogicException('Rejected mutation result has invalid payload.');
        }
    }

    private function assertBusy(): void
    {
        if ($this->refreshResult !== null || $this->blockReason !== null || $this->currentState !== null) {
            throw new LogicException('Busy mutation result has invalid payload.');
        }
    }
}

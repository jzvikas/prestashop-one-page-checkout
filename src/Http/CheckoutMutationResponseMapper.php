<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Http;

use Closure;
use Jzvikas\OnePageCheckout\Checkout\CheckoutMutationExecutionResult;
use Jzvikas\OnePageCheckout\Checkout\CheckoutMutationExecutionStatus;
use Jzvikas\OnePageCheckout\Checkout\CheckoutStateVersioner;
use Jzvikas\OnePageCheckout\Security\CheckoutMutationBlockReason;
use LogicException;

final readonly class CheckoutMutationResponseMapper
{
    public function __construct(private CheckoutStateVersioner $stateVersioner)
    {
    }

    /** @param Closure(string):string $translate */
    public function map(CheckoutMutationExecutionResult $result, Closure $translate): CheckoutJsonResponse
    {
        return match ($result->status) {
            CheckoutMutationExecutionStatus::Completed => $this->completed($result),
            CheckoutMutationExecutionStatus::Rejected => $this->rejected($result, $translate),
            CheckoutMutationExecutionStatus::Busy => CheckoutJsonResponse::error(
                409,
                'checkout_busy',
                $translate('Your checkout is being updated. Please try again.'),
                retryable: true,
            ),
        };
    }

    private function completed(CheckoutMutationExecutionResult $result): CheckoutJsonResponse
    {
        $refreshResult = $result->refreshResult
            ?? throw new LogicException('Completed mutation execution has no refresh result.');
        $body = $refreshResult->toArray();
        $body['retryable'] = false;

        return new CheckoutJsonResponse($refreshResult->success ? 200 : 422, $body);
    }

    /** @param Closure(string):string $translate */
    private function rejected(CheckoutMutationExecutionResult $result, Closure $translate): CheckoutJsonResponse
    {
        $reason = $result->blockReason
            ?? throw new LogicException('Rejected mutation execution has no block reason.');
        $stateVersion = $result->currentState === null
            ? null
            : $this->stateVersioner->version($result->currentState);

        [$statusCode, $message, $retryable] = match ($reason) {
            CheckoutMutationBlockReason::InvalidCsrf => [
                403,
                'Your checkout session could not be verified. Please refresh the page and try again.',
                false,
            ],
            CheckoutMutationBlockReason::MissingCart => [
                409,
                'Your cart is no longer available. Please refresh the page.',
                false,
            ],
            CheckoutMutationBlockReason::InvalidCartBinding => [
                400,
                'The checkout request is invalid.',
                false,
            ],
            CheckoutMutationBlockReason::CrossCart,
            CheckoutMutationBlockReason::CustomerMismatch => [
                403,
                'The checkout request could not be authorized.',
                false,
            ],
            CheckoutMutationBlockReason::StaleState => [
                409,
                'Your checkout changed in another request. Please review the updated information and try again.',
                true,
            ],
        };

        return CheckoutJsonResponse::error(
            $statusCode,
            $reason->value,
            $translate($message),
            $stateVersion,
            $retryable,
        );
    }
}

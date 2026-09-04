<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Security;

use Jzvikas\OnePageCheckout\Checkout\CheckoutServerSelections;
use Jzvikas\OnePageCheckout\Checkout\PrestaShopCheckoutStateFactory;
use Jzvikas\OnePageCheckout\Checkout\StaleCheckoutStateGuard;

final readonly class CheckoutMutationGuard
{
    public function __construct(
        private CheckoutCsrfTokenValidator $csrfTokenValidator,
        private PrestaShopCheckoutStateFactory $stateFactory,
        private StaleCheckoutStateGuard $staleStateGuard,
    ) {
    }

    /** @param array<string,mixed> $request */
    public function evaluate(
        \Context $context,
        array $request,
        ?CheckoutServerSelections $selections = null,
    ): CheckoutMutationGuardResult {
        $submittedToken = $request['token'] ?? $request['static_token'] ?? null;
        if (!$this->csrfTokenValidator->isValid($submittedToken)) {
            return CheckoutMutationGuardResult::block(CheckoutMutationBlockReason::InvalidCsrf);
        }

        $cart = $context->cart ?? null;
        if (!$cart instanceof \Cart || (int) ($cart->id ?? 0) <= 0) {
            return CheckoutMutationGuardResult::block(CheckoutMutationBlockReason::MissingCart);
        }

        $submittedCartId = $this->positiveInteger($request['cartId'] ?? null);
        if ($submittedCartId === null) {
            return CheckoutMutationGuardResult::block(CheckoutMutationBlockReason::InvalidCartBinding);
        }

        if ($submittedCartId !== (int) $cart->id) {
            return CheckoutMutationGuardResult::block(CheckoutMutationBlockReason::CrossCart);
        }

        $cartCustomerId = max(0, (int) ($cart->id_customer ?? 0));
        if ($cartCustomerId > 0) {
            $contextCustomerId = max(0, (int) ($context->customer->id ?? 0));
            if ($contextCustomerId !== $cartCustomerId) {
                return CheckoutMutationGuardResult::block(CheckoutMutationBlockReason::CustomerMismatch);
            }
        }

        $currentState = $this->stateFactory->create($context, $selections);
        $submittedStateVersion = is_string($request['stateVersion'] ?? null)
            ? $request['stateVersion']
            : null;

        if (!$this->staleStateGuard->matches($submittedStateVersion, $currentState)) {
            return CheckoutMutationGuardResult::block(
                CheckoutMutationBlockReason::StaleState,
                $currentState,
            );
        }

        return CheckoutMutationGuardResult::allow($currentState);
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (!is_string($value) || $value === '' || !ctype_digit($value)) {
            return null;
        }

        $normalized = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($normalized) ? $normalized : null;
    }
}

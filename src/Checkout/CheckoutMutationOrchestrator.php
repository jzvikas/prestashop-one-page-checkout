<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout;

use Closure;
use Jzvikas\OnePageCheckout\Concurrency\CheckoutCartLockUnavailable;
use Jzvikas\OnePageCheckout\Concurrency\CheckoutCartMutexInterface;
use Jzvikas\OnePageCheckout\Security\CheckoutCsrfTokenValidator;
use Jzvikas\OnePageCheckout\Security\CheckoutMutationBlockReason;
use Jzvikas\OnePageCheckout\Security\CheckoutMutationGuard;
use LogicException;

final readonly class CheckoutMutationOrchestrator
{
    public function __construct(
        private CheckoutCartMutexInterface $cartMutex,
        private CheckoutCsrfTokenValidator $csrfTokenValidator,
        private CheckoutMutationGuard $mutationGuard,
        private CheckoutSectionDependencyResolver $dependencyResolver,
        private PrestaShopCheckoutStateFactory $stateFactory,
        private CheckoutStateVersioner $stateVersioner,
        private CheckoutServerSelectionsStoreInterface $serverSelectionsStore,
    ) {
    }

    /**
     * @param array<string,mixed> $request
     * @param Closure(CheckoutState,list<CheckoutSection>,CheckoutServerSelections):CheckoutMutationOutcome $mutationHandler
     */
    public function execute(
        \Context $context,
        array $request,
        CheckoutMutation $mutation,
        Closure $mutationHandler,
    ): CheckoutMutationExecutionResult {
        // Cheap preflight rejection prevents invalid-token traffic from consuming per-cart locks.
        // The full guard repeats this check inside the critical section before authorization/write.
        $submittedToken = $request['token'] ?? $request['static_token'] ?? null;
        if (!$this->csrfTokenValidator->isValid($submittedToken)) {
            return CheckoutMutationExecutionResult::rejected(CheckoutMutationBlockReason::InvalidCsrf);
        }

        $cart = $context->cart ?? null;
        $cartId = $cart instanceof \Cart ? (int) ($cart->id ?? 0) : 0;
        if ($cartId <= 0) {
            return CheckoutMutationExecutionResult::rejected(CheckoutMutationBlockReason::MissingCart);
        }

        try {
            return $this->cartMutex->synchronized(
                $cartId,
                function () use ($context, $request, $mutation, $mutationHandler): CheckoutMutationExecutionResult {
                    // Server selections are loaded only after the cart lock is held. This makes
                    // stale-state validation, mutation and persistence one serialized cart operation.
                    $currentSelections = $this->serverSelectionsStore->load($context);
                    $guardResult = $this->mutationGuard->evaluate($context, $request, $currentSelections);
                    if (!$guardResult->allowed) {
                        return CheckoutMutationExecutionResult::rejected(
                            $guardResult->reason ?? throw new LogicException('Blocked mutation guard result has no reason.'),
                            $guardResult->currentState,
                        );
                    }

                    $currentState = $guardResult->currentState
                        ?? throw new LogicException('Allowed mutation guard result has no current state.');
                    $requiredSections = $this->dependencyResolver->affectedBy($mutation);
                    $outcome = $mutationHandler($currentState, $requiredSections, $currentSelections);

                    if (!$outcome instanceof CheckoutMutationOutcome) {
                        throw new LogicException('Checkout mutation handler must return CheckoutMutationOutcome.');
                    }

                    if ($outcome->succeeded()) {
                        $this->assertRequiredSectionsPresent($requiredSections, $outcome->sections);
                        $this->serverSelectionsStore->save($context, $outcome->serverSelections);
                    }

                    // Rebuild from PrestaShop after the handler so Core-calculated totals/eligibility
                    // and newly persisted server selections are represented in the returned state token.
                    $freshSelections = $outcome->succeeded()
                        ? $outcome->serverSelections
                        : $currentSelections;
                    $freshState = $this->stateFactory->create($context, $freshSelections);
                    $freshVersion = $this->stateVersioner->version($freshState);

                    $refreshResult = $outcome->succeeded()
                        ? CheckoutRefreshResult::success($freshVersion, $outcome->sections, $outcome->redirect)
                        : CheckoutRefreshResult::failure($freshVersion, $outcome->errors, $outcome->sections);

                    return CheckoutMutationExecutionResult::completed($refreshResult);
                },
            );
        } catch (CheckoutCartLockUnavailable) {
            return CheckoutMutationExecutionResult::busy();
        }
    }

    /**
     * @param list<CheckoutSection> $requiredSections
     * @param array<string,string> $renderedSections
     */
    private function assertRequiredSectionsPresent(array $requiredSections, array $renderedSections): void
    {
        foreach ($requiredSections as $requiredSection) {
            if (!array_key_exists($requiredSection->value, $renderedSections)) {
                throw new LogicException(sprintf(
                    'Successful %s mutation did not refresh required checkout section %s.',
                    'checkout',
                    $requiredSection->value,
                ));
            }
        }
    }
}

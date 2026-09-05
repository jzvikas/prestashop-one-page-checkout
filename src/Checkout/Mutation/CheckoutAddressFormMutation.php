<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Mutation;

use Closure;
use Jzvikas\OnePageCheckout\Checkout\Address\CheckoutAddressFormException;
use Jzvikas\OnePageCheckout\Checkout\Address\CheckoutAddressFormService;
use Jzvikas\OnePageCheckout\Checkout\CheckoutError;
use Jzvikas\OnePageCheckout\Checkout\CheckoutMutation;
use Jzvikas\OnePageCheckout\Checkout\CheckoutMutationExecutionResult;
use Jzvikas\OnePageCheckout\Checkout\CheckoutMutationOrchestrator;
use Jzvikas\OnePageCheckout\Checkout\CheckoutMutationOutcome;
use Jzvikas\OnePageCheckout\Checkout\CheckoutServerSelections;
use Jzvikas\OnePageCheckout\Checkout\Rendering\CheckoutSectionRendererRegistry;

final readonly class CheckoutAddressFormMutation
{
    public function __construct(
        private CheckoutMutationOrchestrator $orchestrator,
        private CheckoutAddressFormService $addressFormService,
        private CheckoutSectionRendererRegistry $rendererRegistry,
    ) {
    }

    /**
     * @param array<string,mixed> $request
     * @param Closure(string):string $translate
     */
    public function execute(\Context $context, array $request, Closure $translate): CheckoutMutationExecutionResult
    {
        return $this->orchestrator->execute(
            $context,
            $request,
            CheckoutMutation::AddressBookUpdated,
            function ($state, array $requiredSections, CheckoutServerSelections $currentSelections) use ($context, $request, $translate): CheckoutMutationOutcome {
                try {
                    $submission = $this->addressFormService->submit($context, $request);
                } catch (CheckoutAddressFormException $exception) {
                    return CheckoutMutationOutcome::failure(
                        $currentSelections,
                        [new CheckoutError(
                            $exception->errorCode,
                            $translate($exception->getMessage()),
                            $exception->field,
                        )],
                        $this->rendererRegistry->render($context, $requiredSections, $currentSelections),
                    );
                }

                if (!$submission->saved) {
                    return CheckoutMutationOutcome::failure(
                        $currentSelections,
                        [new CheckoutError(
                            'address_validation_failed',
                            $translate('Please correct the highlighted address fields.'),
                            'address',
                        )],
                        $this->rendererRegistry->render($context, $requiredSections, $currentSelections),
                    );
                }

                // Saving an address can change country/state/tax context and Core may replace an
                // address already used by an order with a new historized row. Treat every successful
                // save as authority-changing and require fresh carrier/payment/legal validation.
                $nextSelections = new CheckoutServerSelections();

                return CheckoutMutationOutcome::success(
                    $nextSelections,
                    $this->rendererRegistry->render($context, $requiredSections, $nextSelections),
                );
            },
        );
    }
}

<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Mutation;

use Closure;
use Jzvikas\OnePageCheckout\Checkout\Agreements\CheckoutAgreementSelectionException;
use Jzvikas\OnePageCheckout\Checkout\Agreements\CheckoutAgreementSelectionService;
use Jzvikas\OnePageCheckout\Checkout\CheckoutError;
use Jzvikas\OnePageCheckout\Checkout\CheckoutMutation;
use Jzvikas\OnePageCheckout\Checkout\CheckoutMutationExecutionResult;
use Jzvikas\OnePageCheckout\Checkout\CheckoutMutationOrchestrator;
use Jzvikas\OnePageCheckout\Checkout\CheckoutMutationOutcome;
use Jzvikas\OnePageCheckout\Checkout\CheckoutServerSelections;
use Jzvikas\OnePageCheckout\Checkout\Payment\CheckoutPaymentSelectionException;
use Jzvikas\OnePageCheckout\Checkout\Payment\CheckoutPaymentSelectionParser;
use Jzvikas\OnePageCheckout\Checkout\Payment\CheckoutPaymentSelectionService;
use Jzvikas\OnePageCheckout\Checkout\Rendering\CheckoutSectionRendererRegistry;

final readonly class CheckoutPaymentSelectionMutation
{
    public function __construct(
        private CheckoutMutationOrchestrator $orchestrator,
        private CheckoutPaymentSelectionParser $parser,
        private CheckoutPaymentSelectionService $paymentSelectionService,
        private CheckoutAgreementSelectionService $agreementSelectionService,
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
            CheckoutMutation::PaymentSelected,
            function ($state, array $requiredSections, CheckoutServerSelections $currentSelections) use ($context, $request, $translate): CheckoutMutationOutcome {
                try {
                    $requestedSelection = $this->parser->parse($request);
                    $validatedSelection = $this->paymentSelectionService->validate($context, $requestedSelection);
                    $nextSelections = $this->paymentSelectionService->mergeIntoServerSelections(
                        $validatedSelection,
                        $currentSelections,
                    );
                } catch (CheckoutPaymentSelectionException) {
                    return CheckoutMutationOutcome::failure(
                        $currentSelections,
                        [new CheckoutError(
                            'payment_selection_invalid',
                            $translate('The selected payment option is no longer available. Please choose another payment method.'),
                            'paymentOptionId',
                        )],
                        $this->rendererRegistry->render($context, $requiredSections, $currentSelections),
                    );
                }

                // Payment/module conditions may have changed since the previous approval. Preserve
                // approvals only when the entire currently required set is still exactly valid.
                try {
                    $approvedAgreementKeys = $this->agreementSelectionService->validate(
                        $context,
                        $currentSelections->approvedAgreementKeys,
                    );
                } catch (CheckoutAgreementSelectionException) {
                    $approvedAgreementKeys = [];
                }

                $nextSelections = new CheckoutServerSelections(
                    $nextSelections->selectedPaymentOption,
                    $approvedAgreementKeys,
                );

                return CheckoutMutationOutcome::success(
                    $nextSelections,
                    $this->rendererRegistry->render($context, $requiredSections, $nextSelections),
                );
            },
        );
    }
}

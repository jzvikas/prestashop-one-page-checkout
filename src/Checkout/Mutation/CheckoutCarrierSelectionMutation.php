<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Mutation;

use Closure;
use Jzvikas\OnePageCheckout\Checkout\Carrier\CheckoutCarrierSelectionException;
use Jzvikas\OnePageCheckout\Checkout\Carrier\CheckoutCarrierSelectionParser;
use Jzvikas\OnePageCheckout\Checkout\Carrier\CheckoutCarrierSelectionService;
use Jzvikas\OnePageCheckout\Checkout\CheckoutError;
use Jzvikas\OnePageCheckout\Checkout\CheckoutMutation;
use Jzvikas\OnePageCheckout\Checkout\CheckoutMutationExecutionResult;
use Jzvikas\OnePageCheckout\Checkout\CheckoutMutationOrchestrator;
use Jzvikas\OnePageCheckout\Checkout\CheckoutMutationOutcome;
use Jzvikas\OnePageCheckout\Checkout\CheckoutServerSelections;
use Jzvikas\OnePageCheckout\Checkout\Rendering\CheckoutSectionRendererRegistry;

final readonly class CheckoutCarrierSelectionMutation
{
    public function __construct(
        private CheckoutMutationOrchestrator $orchestrator,
        private CheckoutCarrierSelectionParser $parser,
        private CheckoutCarrierSelectionService $carrierSelectionService,
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
            CheckoutMutation::CarrierSelected,
            function ($state, array $requiredSections, CheckoutServerSelections $currentSelections) use ($context, $request, $translate): CheckoutMutationOutcome {
                try {
                    $requestedSelection = $this->parser->parse($request);
                    $changed = $this->carrierSelectionService->apply($context, $requestedSelection);
                } catch (CheckoutCarrierSelectionException) {
                    return CheckoutMutationOutcome::failure(
                        $currentSelections,
                        [new CheckoutError(
                            'carrier_selection_invalid',
                            $translate('The selected delivery method is no longer available. Please choose another delivery method.'),
                            'deliveryOption',
                        )],
                        $this->rendererRegistry->render($context, $requiredSections, $currentSelections),
                    );
                }

                $nextSelections = $changed ? new CheckoutServerSelections() : $currentSelections;

                return CheckoutMutationOutcome::success(
                    $nextSelections,
                    $this->rendererRegistry->render($context, $requiredSections, $nextSelections),
                );
            },
        );
    }
}

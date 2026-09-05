<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Mutation;

use Closure;
use Jzvikas\OnePageCheckout\Checkout\Agreements\CheckoutAgreementSelectionException;
use Jzvikas\OnePageCheckout\Checkout\Agreements\CheckoutAgreementSelectionParser;
use Jzvikas\OnePageCheckout\Checkout\Agreements\CheckoutAgreementSelectionService;
use Jzvikas\OnePageCheckout\Checkout\CheckoutError;
use Jzvikas\OnePageCheckout\Checkout\CheckoutMutation;
use Jzvikas\OnePageCheckout\Checkout\CheckoutMutationExecutionResult;
use Jzvikas\OnePageCheckout\Checkout\CheckoutMutationOrchestrator;
use Jzvikas\OnePageCheckout\Checkout\CheckoutMutationOutcome;
use Jzvikas\OnePageCheckout\Checkout\CheckoutServerSelections;
use Jzvikas\OnePageCheckout\Checkout\Rendering\CheckoutSectionRendererRegistry;

final readonly class CheckoutAgreementSelectionMutation
{
    public function __construct(
        private CheckoutMutationOrchestrator $orchestrator,
        private CheckoutAgreementSelectionParser $parser,
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
            CheckoutMutation::AgreementsChanged,
            function ($state, array $requiredSections, CheckoutServerSelections $currentSelections) use ($context, $request, $translate): CheckoutMutationOutcome {
                try {
                    $requestedKeys = $this->parser->parse($request);
                    $approvedKeys = $this->agreementSelectionService->validate($context, $requestedKeys);
                } catch (CheckoutAgreementSelectionException) {
                    return CheckoutMutationOutcome::failure(
                        $currentSelections,
                        [new CheckoutError(
                            'agreements_incomplete',
                            $translate('Please accept all required terms and conditions before continuing.'),
                            'agreements',
                        )],
                        $this->rendererRegistry->render($context, $requiredSections, $currentSelections),
                    );
                }

                $nextSelections = $this->agreementSelectionService->mergeIntoServerSelections(
                    $approvedKeys,
                    $currentSelections,
                );

                return CheckoutMutationOutcome::success(
                    $nextSelections,
                    $this->rendererRegistry->render($context, $requiredSections, $nextSelections),
                );
            },
        );
    }
}

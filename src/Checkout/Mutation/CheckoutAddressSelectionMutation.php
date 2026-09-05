<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Mutation;

use Closure;
use Jzvikas\OnePageCheckout\Checkout\Address\CheckoutAddressSelectionException;
use Jzvikas\OnePageCheckout\Checkout\Address\CheckoutAddressSelectionParser;
use Jzvikas\OnePageCheckout\Checkout\Address\CheckoutAddressSelectionService;
use Jzvikas\OnePageCheckout\Checkout\CheckoutError;
use Jzvikas\OnePageCheckout\Checkout\CheckoutMutation;
use Jzvikas\OnePageCheckout\Checkout\CheckoutMutationExecutionResult;
use Jzvikas\OnePageCheckout\Checkout\CheckoutMutationOrchestrator;
use Jzvikas\OnePageCheckout\Checkout\CheckoutMutationOutcome;
use Jzvikas\OnePageCheckout\Checkout\CheckoutServerSelections;
use Jzvikas\OnePageCheckout\Checkout\Rendering\CheckoutSectionRendererRegistry;

final readonly class CheckoutAddressSelectionMutation
{
    public function __construct(
        private CheckoutMutationOrchestrator $orchestrator,
        private CheckoutAddressSelectionParser $parser,
        private CheckoutAddressSelectionService $addressSelectionService,
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
            CheckoutMutation::AddressSelectionUpdated,
            function ($state, array $requiredSections, CheckoutServerSelections $currentSelections) use ($context, $request, $translate): CheckoutMutationOutcome {
                try {
                    $selection = $this->parser->parse($request);
                    $changed = $this->addressSelectionService->apply($context, $selection);
                } catch (CheckoutAddressSelectionException $exception) {
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

                // Address changes can alter taxes, carrier eligibility, payment options and legal
                // requirements. Previously validated payment/agreement selections therefore stop
                // being authoritative and must be rediscovered/re-approved against the fresh cart.
                $nextSelections = $changed
                    ? new CheckoutServerSelections()
                    : $currentSelections;

                return CheckoutMutationOutcome::success(
                    $nextSelections,
                    $this->rendererRegistry->render($context, $requiredSections, $nextSelections),
                );
            },
        );
    }
}

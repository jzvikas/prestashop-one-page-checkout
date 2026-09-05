<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Mutation;

use Closure;
use Jzvikas\OnePageCheckout\Checkout\CheckoutError;
use Jzvikas\OnePageCheckout\Checkout\CheckoutMutation;
use Jzvikas\OnePageCheckout\Checkout\CheckoutMutationExecutionResult;
use Jzvikas\OnePageCheckout\Checkout\CheckoutMutationOrchestrator;
use Jzvikas\OnePageCheckout\Checkout\CheckoutMutationOutcome;
use Jzvikas\OnePageCheckout\Checkout\CheckoutServerSelections;
use Jzvikas\OnePageCheckout\Checkout\Identity\CheckoutIdentityException;
use Jzvikas\OnePageCheckout\Checkout\Identity\CheckoutIdentityService;
use Jzvikas\OnePageCheckout\Checkout\Rendering\CheckoutSectionRendererRegistry;
use Jzvikas\OnePageCheckout\Checkout\Rendering\IdentitySectionRenderer;

final readonly class CheckoutIdentityMutation
{
    public function __construct(
        private CheckoutMutationOrchestrator $orchestrator,
        private CheckoutIdentityService $identityService,
        private CheckoutSectionRendererRegistry $rendererRegistry,
        private IdentitySectionRenderer $identityRenderer,
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
            CheckoutMutation::IdentityUpdated,
            function ($state, array $requiredSections, CheckoutServerSelections $currentSelections) use ($context, $request, $translate): CheckoutMutationOutcome {
                try {
                    $submission = $this->identityService->submit($context, $request);
                } catch (CheckoutIdentityException $exception) {
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

                if (!$submission->completed) {
                    $sections = $this->rendererRegistry->render($context, $requiredSections, $currentSelections);
                    $sections['identity'] = $this->identityRenderer->renderWithForms(
                        $context,
                        $submission->registerFormHtml,
                        $submission->loginFormHtml,
                    );

                    return CheckoutMutationOutcome::failure(
                        $currentSelections,
                        [new CheckoutError(
                            'identity_validation_failed',
                            $translate('Please correct the highlighted customer information.'),
                            'identity',
                        )],
                        $sections,
                    );
                }

                // Customer creation/login can change groups, cart rules, prices, addresses and
                // eligibility. Never carry payment or agreement authority across that transition.
                $nextSelections = new CheckoutServerSelections();

                return CheckoutMutationOutcome::success(
                    $nextSelections,
                    $this->rendererRegistry->render($context, $requiredSections, $nextSelections),
                );
            },
        );
    }
}

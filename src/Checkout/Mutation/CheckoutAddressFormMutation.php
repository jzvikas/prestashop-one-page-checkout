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
use Jzvikas\OnePageCheckout\Checkout\Rendering\AddressesSectionRenderer;
use Jzvikas\OnePageCheckout\Checkout\Rendering\CheckoutSectionRendererRegistry;

final readonly class CheckoutAddressFormMutation
{
    public function __construct(
        private CheckoutMutationOrchestrator $orchestrator,
        private CheckoutAddressFormService $addressFormService,
        private CheckoutSectionRendererRegistry $rendererRegistry,
        private AddressesSectionRenderer $addressesRenderer,
    ) {
    }

    /**
     * @param array<string,mixed> $request
     * @param Closure(string):string $translate
     */
    public function execute(\Context $context, array $request, Closure $translate): CheckoutMutationExecutionResult
    {
        $action = isset($request['addressAction']) && is_string($request['addressAction'])
            ? $request['addressAction']
            : '';
        $mutation = $action === 'present'
            ? CheckoutMutation::AddressEditorChanged
            : CheckoutMutation::AddressBookUpdated;

        return $this->orchestrator->execute(
            $context,
            $request,
            $mutation,
            function ($state, array $requiredSections, CheckoutServerSelections $currentSelections) use ($context, $request, $translate, $action): CheckoutMutationOutcome {
                if ($action === 'present') {
                    return $this->presentEditor($context, $request, $requiredSections, $currentSelections, $translate);
                }
                if ($action !== 'save') {
                    return CheckoutMutationOutcome::failure(
                        $currentSelections,
                        [new CheckoutError('address_action_invalid', $translate('The address action is invalid.'), 'addressAction')],
                        $this->rendererRegistry->render($context, $requiredSections, $currentSelections),
                    );
                }

                try {
                    $submission = $this->addressFormService->submit($context, $request);
                    $role = $this->addressFormService->role($request['addressRole'] ?? null);
                } catch (CheckoutAddressFormException $exception) {
                    return CheckoutMutationOutcome::failure(
                        $currentSelections,
                        [new CheckoutError($exception->errorCode, $translate($exception->getMessage()), $exception->field)],
                        $this->rendererRegistry->render($context, $requiredSections, $currentSelections),
                    );
                }

                if (!$submission->saved) {
                    $sections = $this->rendererRegistry->render($context, $requiredSections, $currentSelections);
                    $sections['addresses'] = $this->addressesRenderer->renderWithAddressEditor(
                        $context,
                        $submission->formHtml,
                        $role,
                        ($request['useSameAddress'] ?? '0') === '1',
                    );

                    return CheckoutMutationOutcome::failure(
                        $currentSelections,
                        [new CheckoutError('address_validation_failed', $translate('Please correct the highlighted address fields.'), 'address')],
                        $sections,
                    );
                }

                $nextSelections = new CheckoutServerSelections();

                return CheckoutMutationOutcome::success(
                    $nextSelections,
                    $this->rendererRegistry->render($context, $requiredSections, $nextSelections),
                );
            },
        );
    }

    /**
     * @param array<string,mixed> $request
     * @param list<\Jzvikas\OnePageCheckout\Checkout\CheckoutSection> $requiredSections
     */
    private function presentEditor(
        \Context $context,
        array $request,
        array $requiredSections,
        CheckoutServerSelections $currentSelections,
        Closure $translate,
    ): CheckoutMutationOutcome {
        try {
            $role = $this->addressFormService->role($request['addressRole'] ?? null);
            $formHtml = $this->addressFormService->present($context, $request);
        } catch (CheckoutAddressFormException $exception) {
            return CheckoutMutationOutcome::failure(
                $currentSelections,
                [new CheckoutError($exception->errorCode, $translate($exception->getMessage()), $exception->field)],
                $this->rendererRegistry->render($context, $requiredSections, $currentSelections),
            );
        }

        $sections = $this->rendererRegistry->render($context, $requiredSections, $currentSelections);
        $sections['addresses'] = $this->addressesRenderer->renderWithAddressEditor(
            $context,
            $formHtml,
            $role,
            ($request['useSameAddress'] ?? '0') === '1',
        );

        return CheckoutMutationOutcome::success($currentSelections, $sections);
    }
}

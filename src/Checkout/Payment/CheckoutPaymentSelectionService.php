<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Payment;

use Jzvikas\OnePageCheckout\Checkout\CheckoutServerSelections;
use Jzvikas\OnePageCheckout\Checkout\Rendering\CheckoutPaymentOptionsPresenterInterface;
use RuntimeException;

final readonly class CheckoutPaymentSelectionService
{
    public function __construct(
        private CheckoutPaymentOptionsPresenterInterface $paymentOptionsPresenter,
    ) {
    }

    public function validate(
        \Context $context,
        CheckoutPaymentSelection $requestedSelection,
    ): CheckoutPaymentSelection {
        $presented = $this->paymentOptionsPresenter->present($context);
        $paymentOptions = $presented['paymentOptions'] ?? null;
        if (!is_array($paymentOptions)) {
            throw new RuntimeException('Presented payment options are missing or invalid.');
        }

        $moduleOptions = $paymentOptions[$requestedSelection->moduleName] ?? null;
        if (!is_array($moduleOptions)) {
            throw new CheckoutPaymentSelectionException('The selected payment option is no longer available.');
        }

        foreach ($moduleOptions as $option) {
            if (!is_array($option) || !isset($option['id']) || !is_string($option['id'])) {
                continue;
            }

            if (!hash_equals($option['id'], $requestedSelection->optionId)) {
                continue;
            }

            $presentedModuleName = $option['module_name'] ?? $requestedSelection->moduleName;
            if (!is_string($presentedModuleName) || !hash_equals($presentedModuleName, $requestedSelection->moduleName)) {
                continue;
            }

            return new CheckoutPaymentSelection($option['id'], $requestedSelection->moduleName);
        }

        throw new CheckoutPaymentSelectionException('The selected payment option is no longer available.');
    }

    public function mergeIntoServerSelections(
        CheckoutPaymentSelection $validatedSelection,
        CheckoutServerSelections $currentSelections,
    ): CheckoutServerSelections {
        return new CheckoutServerSelections(
            $validatedSelection->stateKey(),
            $currentSelections->approvedAgreementKeys,
        );
    }
}

<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Rendering;

use Jzvikas\OnePageCheckout\Checkout\CheckoutSection;
use Jzvikas\OnePageCheckout\Checkout\CheckoutServerSelections;

final readonly class PaymentSectionRenderer implements CheckoutStateAwareSectionRendererInterface
{
    public function __construct(
        private CheckoutPaymentOptionsPresenterInterface $paymentOptionsPresenter,
        private CheckoutTemplateRendererInterface $templateRenderer,
    ) {
    }

    public function section(): CheckoutSection
    {
        return CheckoutSection::Payment;
    }

    public function render(\Context $context): string
    {
        return $this->renderWithSelections($context, new CheckoutServerSelections());
    }

    public function renderWithSelections(\Context $context, CheckoutServerSelections $selections): string
    {
        $variables = $this->paymentOptionsPresenter->present($context);
        $paymentOptions = $variables['paymentOptions'] ?? [];

        foreach ($paymentOptions as $moduleName => &$moduleOptions) {
            if (!is_string($moduleName) || !is_array($moduleOptions)) {
                continue;
            }
            foreach ($moduleOptions as &$option) {
                if (!is_array($option) || !isset($option['id']) || !is_string($option['id'])) {
                    continue;
                }
                $stateKey = $moduleName . ':' . $option['id'];
                $option['jzopc_selected'] = $selections->selectedPaymentOption !== null
                    && hash_equals($selections->selectedPaymentOption, $stateKey);
            }
            unset($option);
        }
        unset($moduleOptions);

        $variables['paymentOptions'] = $paymentOptions;

        return $this->templateRenderer->render($context, 'sections/payment.tpl', $variables);
    }
}

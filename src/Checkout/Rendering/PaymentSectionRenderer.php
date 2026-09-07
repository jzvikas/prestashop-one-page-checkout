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
        $freeOrderStateKey = $this->freeOrderStateKey($variables, $paymentOptions);

        foreach ($paymentOptions as $moduleName => &$moduleOptions) {
            if (!is_string($moduleName) || !is_array($moduleOptions)) {
                continue;
            }
            foreach ($moduleOptions as &$option) {
                if (!is_array($option) || !isset($option['id']) || !is_string($option['id'])) {
                    continue;
                }
                $stateKey = $moduleName . ':' . $option['id'];
                $option['jzopc_selected'] = $freeOrderStateKey !== null
                    ? hash_equals($freeOrderStateKey, $stateKey)
                    : ($selections->selectedPaymentOption !== null
                        && hash_equals($selections->selectedPaymentOption, $stateKey));
            }
            unset($option);
        }
        unset($moduleOptions);

        $variables['paymentOptions'] = $paymentOptions;

        return $this->templateRenderer->render($context, 'sections/payment.tpl', $variables);
    }

    /**
     * Core presents exactly one synthetic `free_order` option for a zero-total cart. We only
     * preselect it when that shape is unambiguous; finalization independently re-resolves and
     * validates the same Core option before acquiring the reservation.
     *
     * @param array<string,mixed> $variables
     * @param array<mixed> $paymentOptions
     */
    private function freeOrderStateKey(array $variables, array $paymentOptions): ?string
    {
        if (($variables['isFree'] ?? null) !== true) {
            return null;
        }

        $moduleOptions = $paymentOptions['free_order'] ?? null;
        if (!is_array($moduleOptions) || count($moduleOptions) !== 1) {
            return null;
        }

        $option = $moduleOptions[0] ?? null;
        if (!is_array($option)
            || !isset($option['id'])
            || !is_string($option['id'])
            || $option['id'] === ''
            || (($option['module_name'] ?? null) !== 'free_order')) {
            return null;
        }

        return 'free_order:' . $option['id'];
    }
}

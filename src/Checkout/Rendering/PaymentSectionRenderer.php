<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Rendering;

use Jzvikas\OnePageCheckout\Checkout\CheckoutSection;

final readonly class PaymentSectionRenderer implements CheckoutSectionRendererInterface
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
        return $this->templateRenderer->render(
            $context,
            'sections/payment.tpl',
            $this->paymentOptionsPresenter->present($context),
        );
    }
}

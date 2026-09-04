<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Rendering;

use Jzvikas\OnePageCheckout\Checkout\CheckoutSection;

final readonly class DeliverySectionRenderer implements CheckoutSectionRendererInterface
{
    public function __construct(
        private CheckoutDeliveryOptionsPresenterInterface $deliveryOptionsPresenter,
        private CheckoutTemplateRendererInterface $templateRenderer,
    ) {
    }

    public function section(): CheckoutSection
    {
        return CheckoutSection::Delivery;
    }

    public function render(\Context $context): string
    {
        $variables = $this->deliveryOptionsPresenter->present($context);
        if ($variables['isVirtual']) {
            return '';
        }

        return $this->templateRenderer->render($context, 'sections/delivery.tpl', $variables);
    }
}

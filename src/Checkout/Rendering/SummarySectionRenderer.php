<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Rendering;

use Jzvikas\OnePageCheckout\Checkout\CheckoutSection;

final readonly class SummarySectionRenderer implements CheckoutSectionRendererInterface
{
    public function __construct(
        private CheckoutCartPresenterInterface $cartPresenter,
        private CheckoutTemplateRendererInterface $templateRenderer,
    ) {
    }

    public function section(): CheckoutSection
    {
        return CheckoutSection::Summary;
    }

    public function render(\Context $context): string
    {
        return $this->templateRenderer->render(
            $context,
            'sections/summary.tpl',
            ['cart' => $this->cartPresenter->present($context)],
        );
    }
}

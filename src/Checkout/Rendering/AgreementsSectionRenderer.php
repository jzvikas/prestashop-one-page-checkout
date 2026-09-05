<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Rendering;

use Jzvikas\OnePageCheckout\Checkout\CheckoutSection;

final readonly class AgreementsSectionRenderer implements CheckoutSectionRendererInterface
{
    public function __construct(
        private CheckoutAgreementsPresenterInterface $agreementsPresenter,
        private CheckoutTemplateRendererInterface $templateRenderer,
    ) {
    }

    public function section(): CheckoutSection
    {
        return CheckoutSection::Agreements;
    }

    public function render(\Context $context): string
    {
        return $this->templateRenderer->render(
            $context,
            'sections/agreements.tpl',
            $this->agreementsPresenter->present($context),
        );
    }
}

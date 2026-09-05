<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Rendering;

use Jzvikas\OnePageCheckout\Checkout\CheckoutSection;
use Jzvikas\OnePageCheckout\Checkout\CheckoutServerSelections;

final readonly class AgreementsSectionRenderer implements CheckoutStateAwareSectionRendererInterface
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
        return $this->renderWithSelections($context, new CheckoutServerSelections());
    }

    public function renderWithSelections(\Context $context, CheckoutServerSelections $selections): string
    {
        $variables = $this->agreementsPresenter->present($context);
        $variables['approvedAgreementKeys'] = array_fill_keys($selections->approvedAgreementKeys, true);

        return $this->templateRenderer->render($context, 'sections/agreements.tpl', $variables);
    }
}

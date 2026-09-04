<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Rendering;

use Jzvikas\OnePageCheckout\Checkout\CheckoutSection;

final readonly class AddressesSectionRenderer implements CheckoutSectionRendererInterface
{
    public function __construct(
        private CheckoutAddressBookPresenterInterface $addressBookPresenter,
        private CheckoutTemplateRendererInterface $templateRenderer,
    ) {
    }

    public function section(): CheckoutSection
    {
        return CheckoutSection::Addresses;
    }

    public function render(\Context $context): string
    {
        return $this->templateRenderer->render(
            $context,
            'sections/addresses.tpl',
            $this->addressBookPresenter->present($context),
        );
    }
}

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
        return $this->renderVariables($context, [
            'addressEditorHtml' => null,
            'addressEditorRole' => null,
            'addressEditorUseSameAddress' => false,
        ]);
    }

    public function renderWithAddressEditor(
        \Context $context,
        string $formHtml,
        string $role,
        bool $useSameAddress,
    ): string {
        return $this->renderVariables($context, [
            'addressEditorHtml' => $formHtml,
            'addressEditorRole' => $role,
            'addressEditorUseSameAddress' => $useSameAddress,
        ]);
    }

    /** @param array<string,mixed> $extra */
    private function renderVariables(\Context $context, array $extra): string
    {
        return $this->templateRenderer->render(
            $context,
            'sections/addresses.tpl',
            array_replace($this->addressBookPresenter->present($context), $extra),
        );
    }
}

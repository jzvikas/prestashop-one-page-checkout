<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Integration;

use Jzvikas\OnePageCheckout\Checkout\CheckoutSection;
use Jzvikas\OnePageCheckout\Checkout\CheckoutServerSelectionsStoreInterface;
use Jzvikas\OnePageCheckout\Checkout\Rendering\CheckoutSectionRendererRegistry;
use Jzvikas\OnePageCheckout\Checkout\Rendering\CheckoutTemplateRendererInterface;

final readonly class CheckoutShellRenderer
{
    /** @var list<CheckoutSection> */
    private const RENDERABLE_SECTIONS = [
        CheckoutSection::Identity,
        CheckoutSection::Addresses,
        CheckoutSection::Delivery,
        CheckoutSection::Payment,
        CheckoutSection::Agreements,
        CheckoutSection::Summary,
    ];

    public function __construct(
        private CheckoutServerSelectionsStoreInterface $selectionsStore,
        private CheckoutBrowserBootstrapFactory $bootstrapFactory,
        private CheckoutSectionRendererRegistry $sectionRendererRegistry,
        private CheckoutTemplateRendererInterface $templateRenderer,
    ) {
    }

    public function render(\Context $context): string
    {
        $selections = $this->selectionsStore->load($context);
        $bootstrap = $this->bootstrapFactory->create($context, $selections);
        $sections = $this->sectionRendererRegistry->render(
            $context,
            self::RENDERABLE_SECTIONS,
            $selections,
        );

        return $this->templateRenderer->render(
            $context,
            'checkout-shell.tpl',
            [
                'jzopc_bootstrap' => $bootstrap->toTemplateVariables(),
                'jzopc_sections' => $sections,
            ],
        );
    }
}

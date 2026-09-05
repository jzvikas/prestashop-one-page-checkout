<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Rendering;

use Jzvikas\OnePageCheckout\Checkout\CheckoutSection;
use Jzvikas\OnePageCheckout\Checkout\CheckoutServerSelections;
use LogicException;

final class CheckoutSectionRendererRegistry
{
    /** @var array<string,CheckoutSectionRendererInterface> */
    private array $renderers = [];

    /** @param iterable<CheckoutSectionRendererInterface> $renderers */
    public function __construct(iterable $renderers)
    {
        foreach ($renderers as $renderer) {
            $key = $renderer->section()->value;
            if (isset($this->renderers[$key])) {
                throw new LogicException(sprintf('Duplicate checkout section renderer for %s.', $key));
            }

            $this->renderers[$key] = $renderer;
        }
    }

    /**
     * @param list<CheckoutSection> $sections
     * @return array<string,string>
     */
    public function render(
        \Context $context,
        array $sections,
        ?CheckoutServerSelections $selections = null,
    ): array {
        $rendered = [];
        foreach ($sections as $section) {
            $renderer = $this->renderers[$section->value] ?? null;
            if (!$renderer instanceof CheckoutSectionRendererInterface) {
                throw new LogicException(sprintf('No renderer registered for checkout section %s.', $section->value));
            }

            $rendered[$section->value] = $selections !== null && $renderer instanceof CheckoutStateAwareSectionRendererInterface
                ? $renderer->renderWithSelections($context, $selections)
                : $renderer->render($context);
        }

        return $rendered;
    }
}

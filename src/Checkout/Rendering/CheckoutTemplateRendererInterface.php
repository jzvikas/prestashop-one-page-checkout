<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Rendering;

interface CheckoutTemplateRendererInterface
{
    /** @param array<string,mixed> $variables */
    public function render(\Context $context, string $template, array $variables): string;
}

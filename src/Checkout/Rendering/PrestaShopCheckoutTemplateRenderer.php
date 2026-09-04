<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Rendering;

use RuntimeException;

final readonly class PrestaShopCheckoutTemplateRenderer implements CheckoutTemplateRendererInterface
{
    private const TEMPLATE_PREFIX = 'module:jzonepagecheckout/views/templates/front/';

    public function render(\Context $context, string $template, array $variables): string
    {
        if ($template === '' || str_contains($template, '..') || str_starts_with($template, '/')) {
            throw new RuntimeException('Invalid checkout template path.');
        }

        $smarty = $context->smarty ?? null;
        if (!is_object($smarty) || !method_exists($smarty, 'assign') || !method_exists($smarty, 'fetch')) {
            throw new RuntimeException('Smarty is not available in the checkout context.');
        }

        $smarty->assign($variables);
        $html = $smarty->fetch(self::TEMPLATE_PREFIX . $template);
        if (!is_string($html)) {
            throw new RuntimeException('Checkout template did not render a string.');
        }

        return $html;
    }
}

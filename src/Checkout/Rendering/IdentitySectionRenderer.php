<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Rendering;

use Jzvikas\OnePageCheckout\Checkout\CheckoutSection;
use Jzvikas\OnePageCheckout\Checkout\Identity\CheckoutIdentityService;

final readonly class IdentitySectionRenderer implements CheckoutSectionRendererInterface
{
    public function __construct(
        private CheckoutIdentityService $identityService,
        private CheckoutTemplateRendererInterface $templateRenderer,
    ) {
    }

    public function section(): CheckoutSection
    {
        return CheckoutSection::Identity;
    }

    public function render(\Context $context): string
    {
        return $this->renderVariables($context, $this->identityService->present($context));
    }

    public function renderWithForms(
        \Context $context,
        string $registerFormHtml,
        string $loginFormHtml,
    ): string {
        $variables = $this->identityService->present($context);
        if ($variables['bound']) {
            return $this->renderVariables($context, $variables);
        }

        $variables['registerFormHtml'] = $registerFormHtml;
        $variables['loginFormHtml'] = $loginFormHtml;

        return $this->renderVariables($context, $variables);
    }

    /** @param array<string,mixed> $variables */
    private function renderVariables(\Context $context, array $variables): string
    {
        return $this->templateRenderer->render(
            $context,
            'sections/identity.tpl',
            $variables,
        );
    }
}

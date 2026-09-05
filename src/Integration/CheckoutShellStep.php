<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Integration;

use Symfony\Contracts\Translation\TranslatorInterface;

final class CheckoutShellStep extends \AbstractCheckoutStep
{
    protected $template = 'module:jzonepagecheckout/views/templates/front/checkout-step.tpl';

    public function __construct(
        \Context $context,
        TranslatorInterface $translator,
        private readonly CheckoutShellRenderer $shellRenderer,
    ) {
        parent::__construct($context, $translator);
    }

    public function handleRequest(array $requestParameters = [])
    {
        $this->setReachable(true);
        $this->setCurrent(true);
        $this->setComplete(false);

        return $this;
    }

    public function getIdentifier()
    {
        return 'jzopc-one-page-checkout';
    }

    public function render(array $extraParams = [])
    {
        return $this->renderTemplate(
            $this->getTemplate(),
            $extraParams,
            ['jzopc_shell_html' => $this->shellRenderer->render($this->context)],
        );
    }
}

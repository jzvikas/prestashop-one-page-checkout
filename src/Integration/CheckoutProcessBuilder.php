<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Integration;

use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class CheckoutProcessBuilder
{
    public function __construct(private CheckoutShellRenderer $shellRenderer)
    {
    }

    public function build(
        \Context $context,
        \CheckoutSession $session,
        TranslatorInterface $translator,
    ): \CheckoutProcess {
        $process = new \CheckoutProcess($context, $session);
        $process->addStep(new CheckoutShellStep($context, $translator, $this->shellRenderer));

        return $process;
    }
}

<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Integration;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class CheckoutProcessBuilder
{
    public function __construct(private CheckoutShellRenderer $shellRenderer)
    {
    }

    public function prepareShell(\Context $context): string
    {
        $shellHtml = $this->shellRenderer->render($context);
        if (trim($shellHtml) === '') {
            throw new RuntimeException('Checkout shell rendering returned empty output.');
        }

        return $shellHtml;
    }

    public function build(
        \Context $context,
        \CheckoutSession $session,
        TranslatorInterface $translator,
    ): \CheckoutProcess {
        return $this->buildPrepared(
            $context,
            $session,
            $translator,
            $this->prepareShell($context),
        );
    }

    public function buildPrepared(
        \Context $context,
        \CheckoutSession $session,
        TranslatorInterface $translator,
        string $shellHtml,
    ): \CheckoutProcess {
        if (trim($shellHtml) === '') {
            throw new InvalidArgumentException('Prepared checkout shell must not be empty.');
        }

        $process = new \CheckoutProcess($context, $session);
        $process->addStep(new CheckoutShellStep($context, $translator, $shellHtml));

        return $process;
    }
}

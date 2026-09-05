<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Integration;

use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class LegacyCheckoutRenderAdapter
{
    public function __construct(private CheckoutProcessBuilder $processBuilder)
    {
    }

    /**
     * Replace only the checkout process object supplied by Core's reference-bearing hook payload.
     *
     * @param array<string,mixed> $params
     */
    public function replaceProcess(
        array &$params,
        \Context $context,
        TranslatorInterface $translator,
    ): bool {
        $coreProcess = $params['checkoutProcess'] ?? null;
        if (!$coreProcess instanceof \CheckoutProcess) {
            return false;
        }

        $session = $coreProcess->getCheckoutSession();
        if (!$session instanceof \CheckoutSession) {
            return false;
        }

        // Build the complete replacement, including shell rendering, before touching Core's
        // reference. If any module/template/DB dependency throws, the original Core process
        // remains intact and the module hook can fail back to native checkout safely.
        $replacementProcess = $this->processBuilder->build($context, $session, $translator);
        $params['checkoutProcess'] = $replacementProcess;

        return true;
    }
}

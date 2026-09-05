<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Integration\Provider;

use Jzvikas\OnePageCheckout\Integration\CheckoutProcessBuilder;
use PrestaShop\PrestaShop\Adapter\Order\Checkout\CheckoutProcessProviderInterface;
use PrestaShopBundle\Translation\TranslatorComponent;

/**
 * This class is autoloaded only on the 9.2+ provider path. Older 9.x runtimes must never reference it.
 */
final readonly class CheckoutProcessProvider implements CheckoutProcessProviderInterface
{
    public function __construct(
        private \Context $context,
        private CheckoutProcessBuilder $processBuilder,
    ) {
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function buildCheckoutProcess(
        \CheckoutSession $session,
        TranslatorComponent $translator,
    ): \CheckoutProcess {
        return $this->processBuilder->build($this->context, $session, $translator);
    }
}

<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Integration;

use Jzvikas\OnePageCheckout\Checkout\CheckoutServerSelections;
use Jzvikas\OnePageCheckout\Checkout\CheckoutStateVersioner;
use Jzvikas\OnePageCheckout\Checkout\PrestaShopCheckoutStateFactory;
use UnexpectedValueException;

final readonly class CheckoutShellBootstrapFactory
{
    public function __construct(
        private PrestaShopCheckoutStateFactory $stateFactory,
        private CheckoutStateVersioner $stateVersioner,
    ) {
    }

    public function create(\Context $context, CheckoutServerSelections $selections): CheckoutShellBootstrap
    {
        if (!class_exists('Tools')) {
            throw new UnexpectedValueException('PrestaShop Tools is required to build checkout bootstrap data.');
        }

        $link = $context->link ?? null;
        if (!$link instanceof \Link) {
            throw new UnexpectedValueException('A loaded PrestaShop Link is required to build checkout bootstrap data.');
        }

        $csrfToken = (string) \Tools::getToken(false);
        if ($csrfToken === '') {
            throw new UnexpectedValueException('PrestaShop front-office CSRF token is unavailable.');
        }

        $state = $this->stateFactory->create($context, $selections);

        return new CheckoutShellBootstrap(
            cartId: $state->cartId,
            csrfToken: $csrfToken,
            stateVersion: $this->stateVersioner->version($state),
            paymentSelectionUrl: $this->moduleEndpoint($link, 'paymentselection'),
            agreementsUrl: $this->moduleEndpoint($link, 'agreements'),
        );
    }

    private function moduleEndpoint(\Link $link, string $controller): string
    {
        $url = $link->getModuleLink(
            'jzonepagecheckout',
            $controller,
            ['ajax' => 1],
            true
        );

        if (!is_string($url) || $url === '') {
            throw new UnexpectedValueException(sprintf('Unable to build checkout endpoint URL for %s.', $controller));
        }

        return $url;
    }
}

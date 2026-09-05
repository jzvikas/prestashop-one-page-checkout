<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Integration;

use Jzvikas\OnePageCheckout\Checkout\CheckoutServerSelections;
use Jzvikas\OnePageCheckout\Checkout\CheckoutStateVersioner;
use Jzvikas\OnePageCheckout\Checkout\PrestaShopCheckoutStateFactory;
use RuntimeException;
use UnexpectedValueException;

final readonly class CheckoutBrowserBootstrapFactory
{
    private const MODULE_NAME = 'jzonepagecheckout';

    public function __construct(
        private PrestaShopCheckoutStateFactory $stateFactory,
        private CheckoutStateVersioner $stateVersioner,
    ) {
    }

    public function create(\Context $context, CheckoutServerSelections $selections): CheckoutBrowserBootstrap
    {
        $cart = $context->cart ?? null;
        if (!$cart instanceof \Cart || (int) ($cart->id ?? 0) <= 0) {
            throw new UnexpectedValueException('A loaded PrestaShop cart is required for checkout bootstrap.');
        }

        if (!class_exists('Tools')) {
            throw new RuntimeException('PrestaShop Tools is required for checkout CSRF bootstrap.');
        }

        $csrfToken = (string) \Tools::getToken(false);
        if ($csrfToken === '') {
            throw new RuntimeException('PrestaShop checkout CSRF token is unavailable.');
        }

        $link = $context->link ?? null;
        if (!$link instanceof \Link) {
            throw new RuntimeException('PrestaShop Link is required for checkout endpoint bootstrap.');
        }

        $state = $this->stateFactory->create($context, $selections);
        if ($state->cartId !== (int) $cart->id) {
            throw new RuntimeException('Checkout bootstrap state does not belong to the loaded cart.');
        }

        return new CheckoutBrowserBootstrap(
            cartId: $state->cartId,
            csrfToken: $csrfToken,
            stateVersion: $this->stateVersioner->version($state),
            addressUrl: $this->moduleLink($link, 'addressselection'),
            carrierUrl: $this->moduleLink($link, 'carrierselection'),
            paymentUrl: $this->moduleLink($link, 'paymentselection'),
            agreementsUrl: $this->moduleLink($link, 'agreements'),
        );
    }

    private function moduleLink(\Link $link, string $controller): string
    {
        $url = (string) $link->getModuleLink(
            self::MODULE_NAME,
            $controller,
            [],
            true,
        );
        if ($url === '') {
            throw new RuntimeException(sprintf('Checkout endpoint URL for %s is unavailable.', $controller));
        }

        return $url;
    }
}

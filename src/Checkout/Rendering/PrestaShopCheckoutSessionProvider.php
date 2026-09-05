<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Rendering;

use PrestaShop\PrestaShop\Adapter\Presenter\Cart\CartPresenter;
use PrestaShop\PrestaShop\Adapter\Presenter\Object\ObjectPresenter;
use PrestaShop\PrestaShop\Adapter\Product\PriceFormatter;
use PrestaShop\PrestaShop\Core\FeatureFlag\FeatureFlagSettings;
use PrestaShop\PrestaShop\Core\FeatureFlag\FeatureFlagStateCheckerInterface;

final readonly class PrestaShopCheckoutSessionProvider implements CheckoutSessionProviderInterface
{
    private const IMPROVED_DELIVERY_OPTIONS_PROVIDER = 'PrestaShop\\PrestaShop\\Adapter\\Shipment\\DeliveryOptionsProvider';
    private const IMPROVED_SHIPMENT_FLAG = 'FEATURE_FLAG_IMPROVED_SHIPMENT';

    public function __construct(
        private FeatureFlagStateCheckerInterface $featureFlags,
    ) {
    }

    public function get(\Context $context): object
    {
        $controller = $context->controller ?? null;
        if (is_object($controller) && method_exists($controller, 'getCheckoutSession')) {
            $session = $controller->getCheckoutSession();
            if (!is_object($session)) {
                throw new \RuntimeException('The active checkout controller returned an invalid Core CheckoutSession.');
            }

            return $session;
        }

        return $this->buildForModuleFrontController($context, $controller);
    }

    private function buildForModuleFrontController(\Context $context, mixed $controller): object
    {
        if (!class_exists('CheckoutSession') || !class_exists('DeliveryOptionsFinder')) {
            throw new \RuntimeException('PrestaShop Core checkout session classes are unavailable.');
        }

        $translator = $context->getTranslator();
        $objectPresenter = is_object($controller)
            && isset($controller->objectPresenter)
            && $controller->objectPresenter instanceof ObjectPresenter
                ? $controller->objectPresenter
                : new ObjectPresenter();

        $deliveryOptions = $this->supportsImprovedShipment()
            ? $this->createImprovedDeliveryOptions($context, $translator, $objectPresenter, $controller)
            : new \DeliveryOptionsFinder(
                $context,
                $translator,
                $objectPresenter,
                new PriceFormatter(),
            );

        return new \CheckoutSession($context, $deliveryOptions);
    }

    private function supportsImprovedShipment(): bool
    {
        $providerClass = self::IMPROVED_DELIVERY_OPTIONS_PROVIDER;
        $flagConstant = FeatureFlagSettings::class . '::' . self::IMPROVED_SHIPMENT_FLAG;

        if (!class_exists($providerClass) || !defined($flagConstant)) {
            return false;
        }

        $flagName = constant($flagConstant);

        return is_string($flagName) && $this->featureFlags->isEnabled($flagName);
    }

    private function createImprovedDeliveryOptions(
        \Context $context,
        object $translator,
        ObjectPresenter $objectPresenter,
        mixed $controller,
    ): object {
        $providerClass = self::IMPROVED_DELIVERY_OPTIONS_PROVIDER;
        if (!class_exists($providerClass)) {
            throw new \RuntimeException('Improved shipment delivery provider is unavailable.');
        }

        $cartPresenter = is_object($controller)
            && isset($controller->cart_presenter)
            && $controller->cart_presenter instanceof CartPresenter
                ? $controller->cart_presenter
                : new CartPresenter();

        return new $providerClass(
            $context,
            $translator,
            $objectPresenter,
            new PriceFormatter(),
            $cartPresenter,
        );
    }
}

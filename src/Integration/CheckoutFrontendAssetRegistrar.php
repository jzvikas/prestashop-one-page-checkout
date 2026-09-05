<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Integration;

use RuntimeException;

final readonly class CheckoutFrontendAssetRegistrar
{
    private const MODULE_PATH = 'modules/jzonepagecheckout/';

    public function register(\Context $context): void
    {
        $controller = $context->controller ?? null;
        if (!is_object($controller) || !method_exists($controller, 'registerJavascript')) {
            throw new RuntimeException('Front controller JavaScript registration is unavailable.');
        }

        $controller->registerJavascript(
            'module-jzonepagecheckout-payment',
            self::MODULE_PATH . 'views/js/payment-controller.js',
            [
                'position' => 'bottom',
                'priority' => 150,
            ],
        );
        $controller->registerJavascript(
            'module-jzonepagecheckout-mutations',
            self::MODULE_PATH . 'views/js/checkout-mutation-client.js',
            [
                'position' => 'bottom',
                'priority' => 151,
            ],
        );
        $controller->registerJavascript(
            'module-jzonepagecheckout-final-submit',
            self::MODULE_PATH . 'views/js/final-submit-controller.js',
            [
                'position' => 'bottom',
                'priority' => 152,
            ],
        );
        $controller->registerJavascript(
            'module-jzonepagecheckout-ordinary-payment-submit-guard',
            self::MODULE_PATH . 'views/js/ordinary-payment-submit-guard.js',
            [
                'position' => 'bottom',
                'priority' => 153,
            ],
        );
        $controller->registerJavascript(
            'module-jzonepagecheckout-binary-payment',
            self::MODULE_PATH . 'views/js/binary-payment-controller.js',
            [
                'position' => 'bottom',
                'priority' => 154,
            ],
        );
    }
}

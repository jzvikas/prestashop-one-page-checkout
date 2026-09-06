<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Integration;

use RuntimeException;

final readonly class CheckoutFrontendAssetRegistrar
{
    private const MODULE_PATH = 'modules/jzonepagecheckout/';

    /** @var list<string> */
    private const JAVASCRIPT_PATHS = [
        'views/js/payment-controller.js',
        'views/js/checkout-mutation-client.js',
        'views/js/final-submit-controller.js',
        'views/js/ordinary-payment-submit-guard.js',
        'views/js/binary-payment-controller.js',
        'views/js/payment-handoff-ambiguity-guard.js',
    ];

    /**
     * @return list<string>
     */
    public function shellJavascriptUrls(): array
    {
        if (!defined('_MODULE_DIR_')) {
            throw new RuntimeException('PrestaShop module asset base URI is unavailable.');
        }

        $moduleBase = rtrim((string) constant('_MODULE_DIR_'), '/') . '/jzonepagecheckout/';
        if ($moduleBase === '/jzonepagecheckout/') {
            throw new RuntimeException('PrestaShop module asset base URI is invalid.');
        }

        return array_map(
            static fn (string $path): string => $moduleBase . $path,
            self::JAVASCRIPT_PATHS,
        );
    }

    public function register(\Context $context): void
    {
        $controller = $context->controller ?? null;
        if (!is_object($controller) || !method_exists($controller, 'registerJavascript')) {
            throw new RuntimeException('Front controller JavaScript registration is unavailable.');
        }

        foreach (self::JAVASCRIPT_PATHS as $index => $path) {
            $controller->registerJavascript(
                'module-jzonepagecheckout-' . (string) $index,
                self::MODULE_PATH . $path,
                [
                    'position' => 'bottom',
                    'priority' => 150 + $index,
                ],
            );
        }
    }
}

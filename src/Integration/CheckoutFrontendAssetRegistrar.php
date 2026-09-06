<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Integration;

use RuntimeException;

final readonly class CheckoutFrontendAssetRegistrar
{
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

    /**
     * Compatibility boundary for the existing media/takeover hooks.
     *
     * Required OPC JavaScript is intentionally no longer queued through Core's page-level asset
     * manager: on PrestaShop 9.0/9.1 that queue was finalized before the legacy checkout takeover,
     * which allowed a custom shell to render without its safety runtime. The shell remains the sole
     * delivery boundary for those six files.
     *
     * Core/themed JavaScript remains owned by PrestaShop. In particular, themes declaring Core
     * scripts receive the Core compatibility bundle (`themes/core.js`) from FrontController. OPC
     * must not inject or duplicate that dependency merely to compensate for an incomplete runtime
     * fixture. The compatibility hook therefore only validates the shell-owned OPC manifest.
     */
    public function register(\Context $context): void
    {
        $controller = $context->controller ?? null;
        if (!is_object($controller)) {
            throw new RuntimeException('PrestaShop FrontController boundary is unavailable.');
        }

        $this->shellJavascriptUrls();
    }
}

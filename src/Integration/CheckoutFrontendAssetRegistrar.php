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
     * The checkout still renders Core/third-party identity, carrier and payment hooks/forms. Some
     * legacy integrations legitimately depend on PrestaShop's Core-owned jQuery compatibility
     * asset, while Hummingbird itself does not expose a global jQuery. Requesting Core jQuery from
     * the authoritative FrontController during actionFrontControllerSetMedia preserves those forms
     * without bundling, vendoring or impersonating jQuery inside the OPC module. Repeated calls at
     * later takeover boundaries are idempotent in Core's asset collection.
     */
    public function register(\Context $context): void
    {
        $controller = $context->controller ?? null;
        if (!is_object($controller) || !is_callable([$controller, 'addJquery'])) {
            throw new RuntimeException('PrestaShop FrontController jQuery compatibility boundary is unavailable.');
        }

        $controller->addJquery();
        $this->shellJavascriptUrls();
    }
}

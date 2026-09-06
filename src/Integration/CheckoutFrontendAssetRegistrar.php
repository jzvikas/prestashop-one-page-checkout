<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Integration;

use RuntimeException;

final readonly class CheckoutFrontendAssetRegistrar
{
    private const CORE_JQUERY_ASSET_ID = 'jzopc-core-jquery';

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
     * integrations legitimately depend on PrestaShop's Core-owned jQuery compatibility asset, while
     * Hummingbird itself does not expose a global jQuery. PrestaShop 9's deprecated addJquery()
     * helper only appends to the legacy js_files array, which Hummingbird does not render through its
     * modern asset pipeline. Register the Core-resolved jQuery path through FrontController's modern
     * JavascriptManager instead. A stable asset ID keeps repeated media/provider/legacy calls
     * idempotent without vendoring or impersonating jQuery inside the OPC module.
     */
    public function register(\Context $context): void
    {
        $controller = $context->controller ?? null;
        if (!is_object($controller) || !is_callable([$controller, 'registerJavascript'])) {
            throw new RuntimeException('PrestaShop FrontController JavaScript registration boundary is unavailable.');
        }
        if (!class_exists(\Media::class) || !is_callable([\Media::class, 'getJqueryPath'])) {
            throw new RuntimeException('PrestaShop Core jQuery resolver is unavailable.');
        }

        $jqueryPath = \Media::getJqueryPath();
        if (!is_string($jqueryPath) || $jqueryPath === '') {
            throw new RuntimeException('PrestaShop Core jQuery asset path is unavailable.');
        }

        $controller->registerJavascript(
            self::CORE_JQUERY_ASSET_ID,
            $jqueryPath,
            [
                'position' => 'head',
                'priority' => 0,
            ],
        );

        $this->shellJavascriptUrls();
    }
}

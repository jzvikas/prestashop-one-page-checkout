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
     * Core compatibility dependencies that must exist before Core/third-party checkout fragments
     * execute. Themes declaring core_scripts=true already receive Core's compatibility bundle and
     * must not get a second jQuery instance. Modern themes such as Hummingbird deliberately omit
     * that bundle, so the active OPC shell supplies only Core's own resolved jQuery file.
     *
     * @return list<string>
     */
    public function shellCompatibilityJavascriptUrls(\Context $context): array
    {
        $theme = $context->shop->theme ?? null;
        if (!is_object($theme) || !is_callable([$theme, 'requiresCoreScripts'])) {
            throw new RuntimeException('PrestaShop theme Core-script capability is unavailable.');
        }

        if ((bool) $theme->requiresCoreScripts()) {
            return [];
        }

        return [$this->coreJqueryPath()];
    }

    /**
     * Compatibility boundary for the existing media/takeover hooks.
     *
     * The early media hook still registers Core jQuery through the modern manager when activation is
     * already known. Legacy checkout takeover can happen after the page JavaScript lists have been
     * materialized, so that registration alone is not authoritative. The custom shell independently
     * resolves its compatibility requirement and supplies Core jQuery synchronously only when the
     * active theme declares that Core scripts are not loaded.
     *
     * The six OPC safety scripts remain shell-owned and are never queued through Core's page-level
     * manager. This avoids both a missing runtime on late takeover and duplicate OPC execution.
     */
    public function register(\Context $context): void
    {
        $controller = $context->controller ?? null;
        if (!is_object($controller) || !is_callable([$controller, 'registerJavascript'])) {
            throw new RuntimeException('PrestaShop FrontController JavaScript registration boundary is unavailable.');
        }

        $controller->registerJavascript(
            self::CORE_JQUERY_ASSET_ID,
            $this->coreJqueryPath(),
            [
                'position' => 'head',
                'priority' => 0,
            ],
        );

        $this->shellCompatibilityJavascriptUrls($context);
        $this->shellJavascriptUrls();
    }

    private function coreJqueryPath(): string
    {
        if (!class_exists(\Media::class) || !is_callable([\Media::class, 'getJqueryPath'])) {
            throw new RuntimeException('PrestaShop Core jQuery resolver is unavailable.');
        }

        $jqueryPath = \Media::getJqueryPath();
        if (!is_string($jqueryPath) || $jqueryPath === '') {
            throw new RuntimeException('PrestaShop Core jQuery asset path is unavailable.');
        }

        return $jqueryPath;
    }
}

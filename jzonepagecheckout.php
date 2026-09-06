<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

$autoload = __DIR__ . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

final class JzOnePageCheckout extends Module
{
    public const CONFIG_CHECKOUT_ENABLED = 'JZOPC_CHECKOUT_ENABLED';

    /**
     * Fail closed until version-specific checkout process adapters are runtime-tested end to end.
     * The provider/legacy adapter code exists behind this gate but cannot take over checkout yet.
     */
    private const INTEGRATION_SHELL_READY = false;

    /**
     * Request-local circuit breaker. If checkout assets or process preparation fail, later module
     * hooks in the same request must leave Core's native checkout untouched.
     */
    private bool $checkoutIntegrationFailed = false;

    public function __construct()
    {
        $this->name = 'jzonepagecheckout';
        $this->tab = 'checkout';
        $this->version = '0.4.0';
        $this->author = 'Justinas Zvikas';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = [
            'min' => '9.0.0',
            'max' => '9.99.99',
        ];

        parent::__construct();

        $this->displayName = $this->trans(
            'One Page Checkout',
            [],
            'Modules.Jzonepagecheckout.Admin'
        );
        $this->description = $this->trans(
            'Fast, safe and compatible one-page checkout for PrestaShop 9.',
            [],
            'Modules.Jzonepagecheckout.Admin'
        );
    }

    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    public function getContent()
    {
        $pageClass = \Jzvikas\OnePageCheckout\BackOffice\CheckoutActivationConfigurationPage::class;
        if (!class_exists($pageClass)) {
            return $this->displayError($this->trans(
                'The One Page Checkout configuration service is unavailable. Reinstall the complete module package.',
                [],
                'Modules.Jzonepagecheckout.Admin'
            ));
        }

        $page = new \Jzvikas\OnePageCheckout\BackOffice\CheckoutActivationConfigurationPage(
            module: $this,
            capabilityDetector: new \Jzvikas\OnePageCheckout\Integration\CheckoutCapabilityDetector(
                new \Jzvikas\OnePageCheckout\Integration\PrestaShopRuntimeProbe()
            ),
            activationPolicy: new \Jzvikas\OnePageCheckout\Integration\CheckoutActivationPolicy(),
            configurationKey: self::CONFIG_CHECKOUT_ENABLED,
            integrationShellReady: self::INTEGRATION_SHELL_READY,
        );

        return $page->render();
    }

    public function install()
    {
        if (!$this->integrationClassesAvailable()) {
            return false;
        }

        $hookPlan = \Jzvikas\OnePageCheckout\Integration\CheckoutHookPlan::forPrestaShopVersion((string) _PS_VERSION_);
        if ($hookPlan->hooks === []) {
            return false;
        }

        if (!parent::install()) {
            return false;
        }

        $selectionSchema = new \Jzvikas\OnePageCheckout\Infrastructure\Persistence\CheckoutServerSelectionsSchema();
        $finalizationSchema = new \Jzvikas\OnePageCheckout\Infrastructure\Persistence\CheckoutFinalizationReservationSchema();
        if (!$selectionSchema->install() || !$finalizationSchema->install()) {
            $finalizationSchema->uninstall();
            $selectionSchema->uninstall();
            parent::uninstall();

            return false;
        }

        if (!Configuration::updateValue(self::CONFIG_CHECKOUT_ENABLED, false)) {
            $finalizationSchema->uninstall();
            $selectionSchema->uninstall();
            parent::uninstall();

            return false;
        }

        $hooks = array_values(array_unique([
            ...$hookPlan->hooks,
            'actionFrontControllerSetMedia',
            'actionValidateOrderAfter',
        ]));
        foreach ($hooks as $hookName) {
            if ($this->registerHook($hookName)) {
                continue;
            }

            Configuration::deleteByName(self::CONFIG_CHECKOUT_ENABLED);
            $finalizationSchema->uninstall();
            $selectionSchema->uninstall();
            parent::uninstall();

            return false;
        }

        return true;
    }

    public function enable($force_all = false)
    {
        return parent::enable((bool) $force_all);
    }

    public function disable($force_all = false)
    {
        if (!Configuration::updateValue(self::CONFIG_CHECKOUT_ENABLED, false)) {
            return false;
        }

        return parent::disable((bool) $force_all);
    }

    public function uninstall()
    {
        if (!class_exists(\Jzvikas\OnePageCheckout\Infrastructure\Persistence\CheckoutServerSelectionsSchema::class)
            || !class_exists(\Jzvikas\OnePageCheckout\Infrastructure\Persistence\CheckoutFinalizationReservationSchema::class)) {
            return false;
        }

        $finalizationDeleted = (new \Jzvikas\OnePageCheckout\Infrastructure\Persistence\CheckoutFinalizationReservationSchema())->uninstall();
        $selectionDeleted = (new \Jzvikas\OnePageCheckout\Infrastructure\Persistence\CheckoutServerSelectionsSchema())->uninstall();
        $configurationDeleted = Configuration::deleteByName(self::CONFIG_CHECKOUT_ENABLED);

        return $finalizationDeleted && $selectionDeleted && $configurationDeleted && parent::uninstall();
    }

    public function hookActionCheckoutBuildProcess(array $params = []): mixed
    {
        try {
            if (!$this->isCustomCheckoutActive()) {
                return null;
            }

            $providerInterface = 'PrestaShop\\PrestaShop\\Adapter\\Order\\Checkout\\CheckoutProcessProviderInterface';
            $providerClass = \Jzvikas\OnePageCheckout\Integration\Provider\CheckoutProcessProvider::class;
            if (!interface_exists($providerInterface) || !class_exists($providerClass)) {
                return null;
            }

            $registrar = $this->get(\Jzvikas\OnePageCheckout\Integration\CheckoutFrontendAssetRegistrar::class);
            if (!$registrar instanceof \Jzvikas\OnePageCheckout\Integration\CheckoutFrontendAssetRegistrar) {
                $this->failCheckoutIntegration(
                    'provider_assets_service',
                    new UnexpectedValueException('Checkout frontend asset registrar service is unavailable.')
                );

                return null;
            }
            // setMedia can run before the checkout activation state is final. Register again at the
            // actual provider takeover boundary; PrestaShop keys assets by ID, so this is idempotent.
            $registrar->register($this->context);

            $builder = $this->get(\Jzvikas\OnePageCheckout\Integration\CheckoutProcessBuilder::class);
            if (!$builder instanceof \Jzvikas\OnePageCheckout\Integration\CheckoutProcessBuilder) {
                $this->failCheckoutIntegration(
                    'provider_service',
                    new UnexpectedValueException('Checkout process builder service is unavailable.')
                );

                return null;
            }

            // Pre-render every risky shell dependency before exposing a valid 9.2+ provider. Core's
            // resolver falls back to its native process when this hook returns no valid provider.
            $preparedShellHtml = $builder->prepareShell($this->context);

            return new \Jzvikas\OnePageCheckout\Integration\Provider\CheckoutProcessProvider(
                $this->context,
                $builder,
                $preparedShellHtml,
            );
        } catch (Throwable $exception) {
            $this->failCheckoutIntegration('provider_prepare', $exception);

            return null;
        }
    }

    public function hookActionCheckoutRender(array $params = []): void
    {
        try {
            if (!$this->isCustomCheckoutActive()) {
                return;
            }

            $registrar = $this->get(\Jzvikas\OnePageCheckout\Integration\CheckoutFrontendAssetRegistrar::class);
            if (!$registrar instanceof \Jzvikas\OnePageCheckout\Integration\CheckoutFrontendAssetRegistrar) {
                $this->failCheckoutIntegration(
                    'legacy_assets_service',
                    new UnexpectedValueException('Checkout frontend asset registrar service is unavailable.')
                );

                return;
            }
            // Core's legacy actionCheckoutRender hook is the last trustworthy boundary before this
            // module replaces the already-built process. Re-register the keyed assets here so a
            // too-early setMedia activation decision can never produce an OPC shell without JS.
            $registrar->register($this->context);

            $adapter = $this->get(\Jzvikas\OnePageCheckout\Integration\LegacyCheckoutRenderAdapter::class);
            if (!$adapter instanceof \Jzvikas\OnePageCheckout\Integration\LegacyCheckoutRenderAdapter) {
                $this->failCheckoutIntegration(
                    'legacy_service',
                    new UnexpectedValueException('Legacy checkout render adapter service is unavailable.')
                );

                return;
            }

            $translator = $this->context->getTranslator();
            if (!$translator instanceof \Symfony\Contracts\Translation\TranslatorInterface) {
                $this->failCheckoutIntegration(
                    'legacy_translator',
                    new UnexpectedValueException('Checkout translator service is unavailable.')
                );

                return;
            }

            if (!$adapter->replaceProcess($params, $this->context, $translator)) {
                $this->failCheckoutIntegration(
                    'legacy_contract',
                    new UnexpectedValueException('Core checkout process contract is unavailable.')
                );
            }
        } catch (Throwable $exception) {
            // Core built its native process before actionCheckoutRender. Asset registration and
            // adapter replacement both happen before the replacement is assigned, so any exception
            // here leaves the original Core process untouched.
            $this->failCheckoutIntegration('legacy_prepare', $exception);
        }
    }

    public function hookActionFrontControllerSetMedia(): void
    {
        $controller = $this->context->controller ?? null;
        if (!$controller instanceof OrderController) {
            return;
        }

        try {
            if (!$this->isCustomCheckoutActive()) {
                return;
            }

            $registrar = $this->get(\Jzvikas\OnePageCheckout\Integration\CheckoutFrontendAssetRegistrar::class);
            if (!$registrar instanceof \Jzvikas\OnePageCheckout\Integration\CheckoutFrontendAssetRegistrar) {
                $this->failCheckoutIntegration(
                    'assets_service',
                    new UnexpectedValueException('Checkout frontend asset registrar service is unavailable.')
                );

                return;
            }

            $registrar->register($this->context);
        } catch (Throwable $exception) {
            // Core calls setMedia before OrderController::postProcess/bootstrap. The request-local
            // circuit breaker therefore prevents a later process takeover when required OPC assets
            // could not be registered safely.
            $this->failCheckoutIntegration('assets_register', $exception);
        }
    }

    public function hookActionValidateOrderAfter(array $params = []): void
    {
        $cart = $params['cart'] ?? null;
        if (!$cart instanceof Cart || (int) ($cart->id ?? 0) <= 0) {
            return;
        }

        if (!$this->hasCreatedOrderForCart($params, $cart)) {
            return;
        }

        try {
            $cleanup = $this->get(\Jzvikas\OnePageCheckout\Checkout\Finalization\CheckoutOrderLifecycleCleanup::class);
            if (!$cleanup instanceof \Jzvikas\OnePageCheckout\Checkout\Finalization\CheckoutOrderLifecycleCleanup) {
                return;
            }

            $cleanup->cleanupForCart($cart);
        } catch (Throwable $exception) {
            $this->logOrderCleanupFailure($exception, $cart);
        }
    }

    public function isCustomCheckoutActive(): bool
    {
        if ($this->checkoutIntegrationFailed || !$this->integrationClassesAvailable()) {
            return false;
        }

        try {
            $detector = new \Jzvikas\OnePageCheckout\Integration\CheckoutCapabilityDetector(
                new \Jzvikas\OnePageCheckout\Integration\PrestaShopRuntimeProbe()
            );
            $policy = new \Jzvikas\OnePageCheckout\Integration\CheckoutActivationPolicy();

            return $policy->decide(
                capabilities: $detector->detect(),
                featureEnabled: (bool) Configuration::get(self::CONFIG_CHECKOUT_ENABLED),
                integrationShellReady: self::INTEGRATION_SHELL_READY,
            )->allowed;
        } catch (Throwable $exception) {
            $this->failCheckoutIntegration('activation_policy', $exception);

            return false;
        }
    }

    /** @param array<string,mixed> $params */
    private function hasCreatedOrderForCart(array $params, Cart $cart): bool
    {
        $cartId = (int) $cart->id;
        $candidates = [];
        if (($params['order'] ?? null) instanceof Order) {
            $candidates[] = $params['order'];
        }
        if (is_array($params['orders'] ?? null)) {
            foreach ($params['orders'] as $order) {
                if ($order instanceof Order) {
                    $candidates[] = $order;
                }
            }
        }

        foreach ($candidates as $order) {
            if ((int) ($order->id ?? 0) > 0 && (int) ($order->id_cart ?? 0) === $cartId) {
                return true;
            }
        }

        try {
            return (int) Order::getIdByCartId($cartId) > 0;
        } catch (Throwable) {
            return false;
        }
    }

    private function failCheckoutIntegration(string $stage, Throwable $exception): void
    {
        if ($this->checkoutIntegrationFailed) {
            return;
        }

        $this->checkoutIntegrationFailed = true;

        try {
            $cart = $this->context->cart ?? null;
            $shopId = $cart instanceof Cart
                ? (int) ($cart->id_shop ?? 0)
                : (int) ($this->context->shop->id ?? 0);
            $cartId = $cart instanceof Cart ? (int) ($cart->id ?? 0) : 0;

            PrestaShopLogger::addLog(
                sprintf(
                    'jzonepagecheckout: native checkout fallback [stage=%s] [%s] [shop=%d] [cart=%d]',
                    $stage,
                    $exception::class,
                    $shopId,
                    $cartId,
                ),
                2,
                null,
                'Module',
                (int) ($this->id ?? 0),
                true,
            );
        } catch (Throwable) {
            // A logging failure must never defeat the native-checkout fallback circuit breaker.
        }
    }

    private function logOrderCleanupFailure(Throwable $exception, Cart $cart): void
    {
        try {
            PrestaShopLogger::addLog(
                sprintf(
                    'jzonepagecheckout: post-order checkout-state cleanup failed [%s] [shop=%d] [cart=%d]',
                    $exception::class,
                    (int) ($cart->id_shop ?? 0),
                    (int) ($cart->id ?? 0),
                ),
                2,
                null,
                'Module',
                (int) ($this->id ?? 0),
                true,
            );
        } catch (Throwable) {
            // A cleanup/logging failure must never turn an already-created Core order into a
            // customer-visible payment failure.
        }
    }

    private function integrationClassesAvailable(): bool
    {
        return class_exists(\Jzvikas\OnePageCheckout\Integration\CheckoutHookPlan::class)
            && class_exists(\Jzvikas\OnePageCheckout\Integration\CheckoutCapabilityDetector::class)
            && class_exists(\Jzvikas\OnePageCheckout\Integration\CheckoutActivationPolicy::class)
            && class_exists(\Jzvikas\OnePageCheckout\Integration\PrestaShopRuntimeProbe::class)
            && class_exists(\Jzvikas\OnePageCheckout\Integration\CheckoutProcessBuilder::class)
            && class_exists(\Jzvikas\OnePageCheckout\Integration\LegacyCheckoutRenderAdapter::class)
            && class_exists(\Jzvikas\OnePageCheckout\Integration\CheckoutFrontendAssetRegistrar::class)
            && class_exists(\Jzvikas\OnePageCheckout\Infrastructure\Persistence\CheckoutServerSelectionsSchema::class)
            && class_exists(\Jzvikas\OnePageCheckout\Infrastructure\Persistence\CheckoutFinalizationReservationSchema::class);
    }
}
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

        $hooks = array_values(array_unique([...$hookPlan->hooks, 'actionFrontControllerSetMedia']));
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
        if (!$this->isCustomCheckoutActive()) {
            return null;
        }

        $providerInterface = 'PrestaShop\\PrestaShop\\Adapter\\Order\\Checkout\\CheckoutProcessProviderInterface';
        $providerClass = \Jzvikas\OnePageCheckout\Integration\Provider\CheckoutProcessProvider::class;
        if (!interface_exists($providerInterface) || !class_exists($providerClass)) {
            return null;
        }

        $builder = $this->get(\Jzvikas\OnePageCheckout\Integration\CheckoutProcessBuilder::class);
        if (!$builder instanceof \Jzvikas\OnePageCheckout\Integration\CheckoutProcessBuilder) {
            return null;
        }

        return new \Jzvikas\OnePageCheckout\Integration\Provider\CheckoutProcessProvider(
            $this->context,
            $builder,
        );
    }

    public function hookActionCheckoutRender(array $params = []): void
    {
        if (!$this->isCustomCheckoutActive()) {
            return;
        }

        $adapter = $this->get(\Jzvikas\OnePageCheckout\Integration\LegacyCheckoutRenderAdapter::class);
        if (!$adapter instanceof \Jzvikas\OnePageCheckout\Integration\LegacyCheckoutRenderAdapter) {
            return;
        }

        $translator = $this->context->getTranslator();
        if (!$translator instanceof \Symfony\Contracts\Translation\TranslatorInterface) {
            return;
        }

        $adapter->replaceProcess($params, $this->context, $translator);
    }

    public function hookActionFrontControllerSetMedia(): void
    {
        $controller = $this->context->controller ?? null;
        if (!$controller instanceof OrderController || !$this->isCustomCheckoutActive()) {
            return;
        }

        $registrar = $this->get(\Jzvikas\OnePageCheckout\Integration\CheckoutFrontendAssetRegistrar::class);
        if (!$registrar instanceof \Jzvikas\OnePageCheckout\Integration\CheckoutFrontendAssetRegistrar) {
            return;
        }

        $registrar->register($this->context);
    }

    public function isCustomCheckoutActive(): bool
    {
        if (!$this->integrationClassesAvailable()) {
            return false;
        }

        $detector = new \Jzvikas\OnePageCheckout\Integration\CheckoutCapabilityDetector(
            new \Jzvikas\OnePageCheckout\Integration\PrestaShopRuntimeProbe()
        );
        $policy = new \Jzvikas\OnePageCheckout\Integration\CheckoutActivationPolicy();

        return $policy->decide(
            capabilities: $detector->detect(),
            featureEnabled: (bool) Configuration::get(self::CONFIG_CHECKOUT_ENABLED),
            integrationShellReady: self::INTEGRATION_SHELL_READY,
        )->allowed;
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

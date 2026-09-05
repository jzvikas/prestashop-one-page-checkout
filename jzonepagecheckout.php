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
     * Fail closed until a version-specific checkout process/adapter is implemented and tested.
     * Module installation and hook registration are safe before checkout takeover is enabled.
     */
    private const INTEGRATION_SHELL_READY = false;

    public function __construct()
    {
        $this->name = 'jzonepagecheckout';
        $this->tab = 'checkout';
        $this->version = '0.1.0';
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

        $schema = new \Jzvikas\OnePageCheckout\Infrastructure\Persistence\CheckoutServerSelectionsSchema();
        if (!$schema->install()) {
            parent::uninstall();

            return false;
        }

        if (!Configuration::updateValue(self::CONFIG_CHECKOUT_ENABLED, false)) {
            $schema->uninstall();
            parent::uninstall();

            return false;
        }

        foreach ($hookPlan->hooks as $hookName) {
            if ($this->registerHook($hookName)) {
                continue;
            }

            Configuration::deleteByName(self::CONFIG_CHECKOUT_ENABLED);
            $schema->uninstall();
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
        if (!class_exists(\Jzvikas\OnePageCheckout\Infrastructure\Persistence\CheckoutServerSelectionsSchema::class)) {
            return false;
        }

        $schemaDeleted = (new \Jzvikas\OnePageCheckout\Infrastructure\Persistence\CheckoutServerSelectionsSchema())->uninstall();
        $configurationDeleted = Configuration::deleteByName(self::CONFIG_CHECKOUT_ENABLED);

        return $schemaDeleted && $configurationDeleted && parent::uninstall();
    }

    public function hookActionCheckoutBuildProcess(array $params = []): mixed
    {
        if (!$this->canActivateCustomCheckout()) {
            return null;
        }

        return null;
    }

    public function hookActionCheckoutRender(array $params = []): void
    {
        if (!$this->canActivateCustomCheckout()) {
            return;
        }
    }

    private function canActivateCustomCheckout(): bool
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
            && class_exists(\Jzvikas\OnePageCheckout\Infrastructure\Persistence\CheckoutServerSelectionsSchema::class);
    }
}

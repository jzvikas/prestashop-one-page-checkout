<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Integration;

final class PrestaShopRuntimeProbe implements RuntimeProbeInterface
{
    public function prestashopVersion(): string
    {
        return defined('_PS_VERSION_') ? (string) constant('_PS_VERSION_') : '0.0.0';
    }

    public function interfaceExists(string $interface): bool
    {
        return interface_exists($interface);
    }

    public function hookExists(string $hookName): bool
    {
        // Capability detection may run before the legacy Hook class has been touched in
        // the current request/CLI process. Let PrestaShop's autoloader resolve it instead
        // of treating "not loaded yet" as "not supported".
        if (!class_exists('Hook')) {
            return false;
        }

        return (int) \Hook::getIdByName($hookName) > 0;
    }

    public function moduleIsInstalled(string $moduleName): bool
    {
        if (!class_exists('Module')) {
            return false;
        }

        return \Module::isInstalled($moduleName);
    }

    public function moduleIsEnabled(string $moduleName): bool
    {
        if (!class_exists('Module')) {
            return false;
        }

        return \Module::isEnabled($moduleName);
    }
}

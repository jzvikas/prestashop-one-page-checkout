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
        if (!class_exists('Hook', false)) {
            return false;
        }

        return (int) \Hook::getIdByName($hookName) > 0;
    }

    public function moduleIsInstalled(string $moduleName): bool
    {
        if (!class_exists('Module', false)) {
            return false;
        }

        return \Module::isInstalled($moduleName);
    }

    public function moduleIsEnabled(string $moduleName): bool
    {
        if (!class_exists('Module', false)) {
            return false;
        }

        return \Module::isEnabled($moduleName);
    }
}

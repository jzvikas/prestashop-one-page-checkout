<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Integration;

interface RuntimeProbeInterface
{
    public function prestashopVersion(): string;

    public function interfaceExists(string $interface): bool;

    public function hookExists(string $hookName): bool;

    public function moduleIsInstalled(string $moduleName): bool;

    public function moduleIsEnabled(string $moduleName): bool;
}

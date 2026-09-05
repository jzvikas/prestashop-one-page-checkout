<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$moduleSource = file_get_contents($root . '/jzonepagecheckout.php');
$selectionUpgradeSource = file_get_contents($root . '/upgrade/upgrade-0.2.0.php');
$mediaUpgradeSource = file_get_contents($root . '/upgrade/upgrade-0.3.0.php');
$finalizationUpgradeSource = file_get_contents($root . '/upgrade/upgrade-0.4.0.php');

function assertUpgradeContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

foreach ([$moduleSource, $selectionUpgradeSource, $mediaUpgradeSource, $finalizationUpgradeSource] as $source) {
    assertUpgradeContract(is_string($source) && $source !== '', 'module upgrade contract source must be readable');
}

assertUpgradeContract(str_contains($moduleSource, "$" . "this->version = '0.4.0';"), 'module version must match latest schema upgrade');
assertUpgradeContract(str_contains($selectionUpgradeSource, 'function upgrade_module_0_2_0(Module $module): bool'), '0.2.0 upgrade entry is required');
assertUpgradeContract(str_contains($selectionUpgradeSource, '(new CheckoutServerSelectionsSchema())->install()'), '0.2.0 selection schema upgrade is required');
assertUpgradeContract(str_contains($mediaUpgradeSource, 'function upgrade_module_0_3_0(Module $module): bool'), '0.3.0 upgrade entry is required');
assertUpgradeContract(str_contains($mediaUpgradeSource, "isRegisteredInHook('actionFrontControllerSetMedia')"), '0.3.0 media hook check is required');
assertUpgradeContract(str_contains($mediaUpgradeSource, "registerHook('actionFrontControllerSetMedia')"), '0.3.0 media hook registration is required');
assertUpgradeContract(str_contains($finalizationUpgradeSource, 'function upgrade_module_0_4_0(Module $module): bool'), '0.4.0 upgrade entry is required');
assertUpgradeContract(str_contains($finalizationUpgradeSource, '(new CheckoutFinalizationReservationSchema())->install()'), '0.4.0 finalization schema upgrade is required');
assertUpgradeContract(str_contains($finalizationUpgradeSource, "isRegisteredInHook('actionValidateOrderAfter')"), '0.4.0 must idempotently check the post-order cleanup hook');
assertUpgradeContract(str_contains($finalizationUpgradeSource, "registerHook('actionValidateOrderAfter')"), '0.4.0 must register post-order cleanup on existing installations');
assertUpgradeContract(str_contains($moduleSource, "'actionValidateOrderAfter'"), 'fresh installs must register post-order checkout cleanup');

echo "CheckoutModuleUpgradeContractSmokeTest OK\n";

<?php

declare(strict_types=1);

$moduleSource = file_get_contents(dirname(__DIR__, 2) . '/jzonepagecheckout.php');
$selectionUpgradeSource = file_get_contents(dirname(__DIR__, 2) . '/upgrade/upgrade-0.2.0.php');
$mediaUpgradeSource = file_get_contents(dirname(__DIR__, 2) . '/upgrade/upgrade-0.3.0.php');

assert(is_string($moduleSource));
assert(is_string($selectionUpgradeSource));
assert(is_string($mediaUpgradeSource));
assert(str_contains($moduleSource, "$" . "this->version = '0.3.0';"));
assert(str_contains($selectionUpgradeSource, 'function upgrade_module_0_2_0(Module $module): bool'));
assert(str_contains($selectionUpgradeSource, '(new CheckoutServerSelectionsSchema())->install()'));
assert(str_contains($mediaUpgradeSource, 'function upgrade_module_0_3_0(Module $module): bool'));
assert(str_contains($mediaUpgradeSource, "isRegisteredInHook('actionFrontControllerSetMedia')"));
assert(str_contains($mediaUpgradeSource, "registerHook('actionFrontControllerSetMedia')"));

echo "CheckoutModuleUpgradeContractSmokeTest OK\n";

<?php

declare(strict_types=1);

$moduleSource = file_get_contents(dirname(__DIR__, 2) . '/jzonepagecheckout.php');
$upgradeSource = file_get_contents(dirname(__DIR__, 2) . '/upgrade/upgrade-0.2.0.php');

assert(is_string($moduleSource));
assert(is_string($upgradeSource));
assert(str_contains($moduleSource, "$" . "this->version = '0.2.0';"));
assert(str_contains($upgradeSource, 'function upgrade_module_0_2_0(Module $module): bool'));
assert(str_contains($upgradeSource, '(new CheckoutServerSelectionsSchema())->install()'));

echo "CheckoutModuleUpgradeContractSmokeTest OK\n";

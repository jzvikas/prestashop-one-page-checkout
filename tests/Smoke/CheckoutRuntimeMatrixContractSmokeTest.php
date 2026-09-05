<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

$workflowPath = $root . '/.github/workflows/prestashop-runtime.yml';
$workflow = is_file($workflowPath) ? file_get_contents($workflowPath) : false;
if (!is_string($workflow) || $workflow === '') {
    $fail('PrestaShop runtime workflow is missing.');
}

foreach ([
    "ps_ref: '9.0.3'",
    "family: '9.0'",
    "ps_ref: '9.1.5'",
    "family: '9.1'",
    "ps_ref: '9.2.0-beta.1'",
    "family: '9.2'",
] as $requiredFragment) {
    if (!str_contains($workflow, $requiredFragment)) {
        $fail(sprintf('Runtime workflow is missing required matrix fragment: %s', $requiredFragment));
    }
}

$contracts = [
    'InstalledModuleContract.php',
    'CoreProcessAdapterContract.php',
    'InstalledSmartyShellContract.php',
    'ModuleFrontCheckoutSessionContract.php',
];

foreach ($contracts as $contract) {
    $path = $root . '/tests/Runtime/' . $contract;
    $source = is_file($path) ? file_get_contents($path) : false;
    if (!is_string($source) || $source === '') {
        $fail(sprintf('Runtime contract is missing: %s', $contract));
    }
    if (!str_contains($source, "['9.0', '9.1', '9.2']")) {
        $fail(sprintf('%s does not explicitly accept the supported 9.0/9.1/9.2 runtime families.', $contract));
    }
}

$installedContract = file_get_contents($root . '/tests/Runtime/InstalledModuleContract.php');
if (!is_string($installedContract)) {
    $fail('Installed module runtime contract is unavailable.');
}
if (str_contains($installedContract, "!== '0.3.0'")) {
    $fail('Installed runtime contract still hard-codes the obsolete 0.3.0 module version.');
}
if (!str_contains($installedContract, 'version_compare((string) $module->version, \'0.4.0\', \'<\')')) {
    $fail('Installed runtime contract must require at least the 0.4.0 finalization schema baseline.');
}
if (!str_contains($installedContract, "isRegisteredInHook('actionValidateOrderAfter')")) {
    $fail('Installed runtime contract must verify the successful-order cleanup hook.');
}

fwrite(STDOUT, "Runtime matrix contract source checks OK.\n");

<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$workflow = file_get_contents($root . '/.github/workflows/prestashop-runtime.yml');
$runtimeContract = file_get_contents($root . '/tests/Runtime/FailClosedHttpContract.php');
$module = file_get_contents($root . '/jzonepagecheckout.php');
$mutationController = file_get_contents($root . '/controllers/front/AbstractJzOpcMutationFrontController.php');

if (!is_string($workflow) || !is_string($runtimeContract) || !is_string($module) || !is_string($mutationController)) {
    throw new RuntimeException('Unable to read fail-closed HTTP contract sources.');
}

$requiredWorkflowFragments = [
    "ps_ref: '9.0.3'",
    "ps_ref: '9.1.5'",
    "ps_ref: '9.2.0-beta.1'",
    '--domain=localhost:8080',
    'Start fail-closed Front Office HTTP server',
    'php -S 127.0.0.1:8080 -t /tmp/prestashop /tmp/prestashop/index.php',
    'curl --fail --silent --show-error http://localhost:8080/',
    'Execute fail-closed Front Office HTTP contract',
    'php tests/Runtime/FailClosedHttpContract.php http://localhost:8080',
];

foreach ($requiredWorkflowFragments as $fragment) {
    if (!str_contains($workflow, $fragment)) {
        throw new RuntimeException('Runtime workflow is missing fail-closed HTTP fragment: ' . $fragment);
    }
}

if (str_contains($workflow, '--domain=localhost \\')) {
    throw new RuntimeException('Runtime shop domain must include the loopback HTTP port used by the browser/server contracts.');
}

$requiredContractFragments = [
    "'/order'",
    "'data-jzopc-checkout'",
    "'/modules/jzonepagecheckout/views/js/'",
    "'/modules/jzonepagecheckout/views/css/'",
    "'/module/jzonepagecheckout/finalize'",
    "'checkout_unavailable'",
    "'invalid-test-token'",
    "'invalid-test-state'",
];

foreach ($requiredContractFragments as $fragment) {
    if (!str_contains($runtimeContract, $fragment)) {
        throw new RuntimeException('HTTP runtime contract is missing boundary assertion: ' . $fragment);
    }
}

if (!str_contains($module, 'private const INTEGRATION_SHELL_READY = false;')) {
    throw new RuntimeException('Production integration readiness gate must remain closed.');
}

if (!str_contains($mutationController, "404,\n                'checkout_unavailable'")) {
    throw new RuntimeException('Inactive mutation endpoint must retain the checkout_unavailable 404 contract.');
}

fwrite(STDOUT, "Fail-closed HTTP runtime source contract completed successfully.\n");

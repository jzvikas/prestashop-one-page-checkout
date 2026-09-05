<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$runtime = file_get_contents($root . '/tests/Runtime/IntegrationFailureIsolationContract.php');
$workflow = file_get_contents($root . '/.github/workflows/prestashop-runtime.yml');
$module = file_get_contents($root . '/jzonepagecheckout.php');

function assertInstalledIntegrationFailureIsolation(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

foreach ([$runtime, $workflow, $module] as $source) {
    assertInstalledIntegrationFailureIsolation(
        is_string($source) && $source !== '',
        'installed integration failure isolation source must be readable',
    );
}

assertInstalledIntegrationFailureIsolation(
    str_contains($runtime, "['9.0', '9.1', '9.2']"),
    'failure isolation runtime contract must execute on every supported runtime family',
);
assertInstalledIntegrationFailureIsolation(
    str_contains($runtime, 'final class InjectedFailingSelectionsStore implements CheckoutServerSelectionsStoreInterface')
        && str_contains($runtime, "throw new RuntimeException('Injected checkout selection read failure.')"),
    'runtime contract must inject failure at the real shell persistence-read boundary',
);
assertInstalledIntegrationFailureIsolation(
    str_contains($runtime, 'new LegacyCheckoutRenderAdapter($failingBuilder)')
        && str_contains($runtime, "if ((\$params['checkoutProcess'] ?? null) !== \$coreProcess)"),
    'legacy runtime failure must prove the original Core checkout process reference remains untouched',
);
assertInstalledIntegrationFailureIsolation(
    str_contains($runtime, '$coreProcess->getCheckoutSession() !== $session'),
    'legacy failure must prove the native Core process still owns the exact CheckoutSession',
);
assertInstalledIntegrationFailureIsolation(
    str_contains($runtime, '$failingBuilder->prepareShell($context)')
        && str_contains($runtime, 'new Jzvikas\\OnePageCheckout\\Integration\\Provider\\CheckoutProcessProvider(')
        && str_contains($runtime, 'if ($selectionsStore->loadCalls !== 1)'),
    '9.2 runtime contract must prove eager failure occurs before provider exposure and provider build does not rerender risky shell dependencies',
);
assertInstalledIntegrationFailureIsolation(
    !str_contains($runtime, 'Configuration::updateValue(')
        && !str_contains($runtime, 'validateOrder(')
        && !str_contains($runtime, 'finalizationAction')
        && !str_contains($runtime, 'ReflectionClass'),
    'failure injection must not create a readiness/configuration/order/finalization bypass',
);
assertInstalledIntegrationFailureIsolation(
    str_contains($workflow, 'Execute integration failure isolation contract')
        && str_contains($workflow, 'php tests/Runtime/IntegrationFailureIsolationContract.php')
        && str_contains($workflow, '"${{ matrix.family }}"'),
    'installed runtime matrix must execute failure isolation for every family',
);
assertInstalledIntegrationFailureIsolation(
    str_contains($module, 'private const INTEGRATION_SHELL_READY = false;'),
    'installed failure injection must not open the production readiness gate',
);

fwrite(STDOUT, "Installed integration failure isolation source contract OK.\n");

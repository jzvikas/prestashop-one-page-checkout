<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$builder = file_get_contents($root . '/tests/Runtime/build-active-checkout-fixture.sh');
$module = file_get_contents($root . '/jzonepagecheckout.php');

function assertActiveRuntimeFixtureIsolation(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

assertActiveRuntimeFixtureIsolation(is_string($builder) && $builder !== '', 'active runtime fixture builder must be readable');
assertActiveRuntimeFixtureIsolation(is_string($module) && $module !== '', 'production module source must be readable');

assertActiveRuntimeFixtureIsolation(
    str_contains($builder, 'JZOPC_RUNTIME_ACTIVE_FIXTURE')
        && str_contains($builder, 'Refusing to build active checkout fixture'),
    'active fixture creation must require an explicit test-only environment guard',
);
assertActiveRuntimeFixtureIsolation(
    str_contains($builder, '/tmp/jzopc-active-fixture|/tmp/jzopc-active-fixture-*'),
    'active fixture output must be restricted to an explicit temporary path',
);
assertActiveRuntimeFixtureIsolation(
    str_contains($builder, "--exclude='.git'")
        && str_contains($builder, 'source_module="$source_root/jzonepagecheckout.php"')
        && str_contains($builder, 'target_module="$target_root/jzonepagecheckout.php"'),
    'fixture builder must copy the repository and patch only the temporary module file',
);
assertActiveRuntimeFixtureIsolation(
    str_contains($builder, 'private const INTEGRATION_SHELL_READY = false;')
        && str_contains($builder, 'private const INTEGRATION_SHELL_READY = true;')
        && str_contains($builder, 'Source readiness gate changed while creating temporary fixture.'),
    'fixture builder must verify one closed source gate before and after temporary patching',
);
assertActiveRuntimeFixtureIsolation(
    !str_contains($builder, 'sed -i "$source_module"')
        && !str_contains($builder, 'file_put_contents($argv[2]')
        && !str_contains($builder, 'Configuration::updateValue'),
    'fixture builder itself must not mutate source code or shop configuration',
);
assertActiveRuntimeFixtureIsolation(
    str_contains($module, 'private const INTEGRATION_SHELL_READY = false;')
        && !str_contains($module, 'private const INTEGRATION_SHELL_READY = true;'),
    'production repository must remain fail closed while active HTTP/browser verification is pending',
);

fwrite(STDOUT, "Active runtime fixture isolation source contract OK.\n");

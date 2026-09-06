<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$builder = file_get_contents($root . '/tests/Runtime/build-active-checkout-fixture.sh');
$instrumenter = file_get_contents($root . '/tests/Runtime/InstrumentActiveCheckoutFailureFixture.php');
$assetRegistrar = file_get_contents($root . '/src/Integration/CheckoutFrontendAssetRegistrar.php');
$module = file_get_contents($root . '/jzonepagecheckout.php');

function assertActiveRuntimeFixtureIsolation(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

assertActiveRuntimeFixtureIsolation(is_string($builder) && $builder !== '', 'active runtime fixture builder must be readable');
assertActiveRuntimeFixtureIsolation(is_string($instrumenter) && $instrumenter !== '', 'active runtime failure instrumenter must be readable');
assertActiveRuntimeFixtureIsolation(is_string($assetRegistrar) && $assetRegistrar !== '', 'production asset registrar must be readable');
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

$assetRegisterAnchor = <<<'PHP'
    public function register(\Context $context): void
    {
        $controller = $context->controller ?? null;
        if (!is_object($controller) || !is_callable([$controller, 'registerJavascript'])) {
PHP;
assertActiveRuntimeFixtureIsolation(
    substr_count($assetRegistrar, $assetRegisterAnchor) === 1,
    'production asset registrar must expose the exact modern Core-jQuery/shell-manifest compatibility boundary instrumented by the runtime fixture',
);
assertActiveRuntimeFixtureIsolation(
    str_contains($assetRegistrar, '$jqueryPath = \\Media::getJqueryPath();')
        && str_contains($assetRegistrar, '$controller->registerJavascript(')
        && str_contains($assetRegistrar, '$this->shellJavascriptUrls();')
        && !str_contains($assetRegistrar, '$controller->addJquery();'),
    'production asset compatibility boundary must resolve/register Core-owned jQuery through the modern asset manager and validate the shell manifest',
);
assertActiveRuntimeFixtureIsolation(
    str_contains($instrumenter, "'path' => 'src/Integration/CheckoutFrontendAssetRegistrar.php'")
        && str_contains($instrumenter, "'marker' => '.jzopc-runtime-failure-assets'")
        && str_contains($instrumenter, $assetRegisterAnchor)
        && str_contains($instrumenter, 'Injected active checkout asset compatibility validation failure.'),
    'runtime failure instrumenter must stay aligned with the modern Core-jQuery/shell-manifest compatibility boundary',
);

assertActiveRuntimeFixtureIsolation(
    str_contains($module, 'private const INTEGRATION_SHELL_READY = false;')
        && !str_contains($module, 'private const INTEGRATION_SHELL_READY = true;'),
    'production repository must remain fail closed while active HTTP/browser verification is pending',
);

fwrite(STDOUT, "Active runtime fixture isolation source contract OK.\n");

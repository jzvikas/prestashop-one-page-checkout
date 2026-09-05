<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$module = file_get_contents($root . '/jzonepagecheckout.php');
$builder = file_get_contents($root . '/src/Integration/CheckoutProcessBuilder.php');
$step = file_get_contents($root . '/src/Integration/CheckoutShellStep.php');
$legacy = file_get_contents($root . '/src/Integration/LegacyCheckoutRenderAdapter.php');
$provider = file_get_contents($root . '/src/Integration/Provider/CheckoutProcessProvider.php');

function assertIntegrationFailureContainment(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

foreach ([$module, $builder, $step, $legacy, $provider] as $source) {
    assertIntegrationFailureContainment(is_string($source) && $source !== '', 'integration failure containment source must be readable');
}

assertIntegrationFailureContainment(
    str_contains($module, 'private bool $checkoutIntegrationFailed = false;')
        && str_contains($module, 'if ($this->checkoutIntegrationFailed || !$this->integrationClassesAvailable())'),
    'request-local integration failure must block every later checkout takeover decision'
);
assertIntegrationFailureContainment(
    str_contains($module, '$preparedShellHtml = $builder->prepareShell($this->context);')
        && str_contains($module, "failCheckoutIntegration('provider_prepare', $exception)")
        && str_contains($module, 'return null;'),
    '9.2+ provider hook must prepare risky shell dependencies before exposing a provider and fall back with null on failure'
);
assertIntegrationFailureContainment(
    str_contains($module, "failCheckoutIntegration('legacy_prepare', $exception)")
        && str_contains($legacy, '$replacementProcess = $this->processBuilder->build')
        && str_contains($legacy, "$" . "params['checkoutProcess'] = $" . "replacementProcess"),
    'legacy hook must keep Core process replacement after successful eager preparation only'
);
assertIntegrationFailureContainment(
    strpos($legacy, '$replacementProcess = $this->processBuilder->build')
        < strpos($legacy, "$" . "params['checkoutProcess'] = $" . "replacementProcess"),
    'legacy adapter must never mutate the Core process reference before replacement construction succeeds'
);
assertIntegrationFailureContainment(
    str_contains($module, "failCheckoutIntegration('assets_register', $exception)")
        && str_contains($module, 'Core calls setMedia before OrderController::postProcess/bootstrap'),
    'asset registration failure must trip the request circuit breaker before checkout bootstrap'
);
assertIntegrationFailureContainment(
    str_contains($builder, 'public function prepareShell(')
        && str_contains($builder, '$this->shellRenderer->render($context)')
        && str_contains($builder, "throw new RuntimeException('Checkout shell rendering returned empty output.')"),
    'shell preparation must execute render dependencies before takeover and reject empty output'
);
assertIntegrationFailureContainment(
    str_contains($builder, 'public function buildPrepared(')
        && str_contains($builder, 'new \\CheckoutProcess($context, $session)')
        && str_contains($builder, 'new CheckoutShellStep($context, $translator, $shellHtml)'),
    'prepared process must still reuse the exact Core CheckoutSession passed by the active integration path'
);
assertIntegrationFailureContainment(
    str_contains($step, 'private readonly string $shellHtml')
        && str_contains($step, "['jzopc_shell_html' => $this->shellHtml]")
        && !str_contains($step, 'CheckoutShellRenderer'),
    'Core step rendering must consume already-prepared HTML rather than invoking risky shell dependencies after takeover'
);
assertIntegrationFailureContainment(
    str_contains($provider, 'private string $preparedShellHtml')
        && str_contains($provider, '$this->processBuilder->buildPrepared(')
        && str_contains($provider, '$this->preparedShellHtml'),
    '9.2+ provider must consume the prepared shell while still receiving Core supplied session/translator arguments'
);
assertIntegrationFailureContainment(
    str_contains($module, 'jzonepagecheckout: native checkout fallback [stage=%s] [%s] [shop=%d] [cart=%d]')
        && !str_contains($module, '$exception->getMessage()'),
    'fallback logging must be diagnostic without leaking exception messages or request/payment payloads'
);
assertIntegrationFailureContainment(
    str_contains($module, 'private const INTEGRATION_SHELL_READY = false;'),
    'production readiness gate must remain closed while fallback behavior is unverified at runtime'
);

echo "Checkout integration failure containment contract smoke tests passed.\n";

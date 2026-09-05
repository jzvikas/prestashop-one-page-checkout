<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$module = file_get_contents($root . '/jzonepagecheckout.php');
$builder = file_get_contents($root . '/src/Integration/CheckoutProcessBuilder.php');
$step = file_get_contents($root . '/src/Integration/CheckoutShellStep.php');
$legacy = file_get_contents($root . '/src/Integration/LegacyCheckoutRenderAdapter.php');
$provider = file_get_contents($root . '/src/Integration/Provider/CheckoutProcessProvider.php');
$services = file_get_contents($root . '/config/services.yml');

foreach ([$module, $builder, $step, $legacy, $provider, $services] as $source) {
    assert(is_string($source) && $source !== '');
}

assert(str_contains($module, 'private const INTEGRATION_SHELL_READY = false;'));
assert(str_contains($module, "interface_exists($" . "providerInterface) || !class_exists($" . "providerClass)"));
assert(str_contains($module, 'Integration\\Provider\\CheckoutProcessProvider'));
assert(str_contains($module, 'LegacyCheckoutRenderAdapter::class'));
assert(str_contains($module, 'hookActionFrontControllerSetMedia(): void'));
assert(str_contains($module, 'instanceof OrderController'));
assert(str_contains($module, '!$this->isCustomCheckoutActive()'));

assert(str_contains($builder, 'new \\CheckoutProcess($context, $session)'));
assert(str_contains($builder, 'new CheckoutShellStep('));
assert(str_contains($legacy, "$" . "coreProcess->getCheckoutSession()"));
assert(str_contains($legacy, "$" . "params['checkoutProcess'] = $" . "this->processBuilder->build"));

assert(str_contains($step, 'extends \\AbstractCheckoutStep'));
assert(str_contains($step, 'renderTemplate('), 'Shell step must preserve actionCheckoutStepRenderTemplate through Core rendering.');
assert(str_contains($step, "return 'jzopc-one-page-checkout';"));
assert(str_contains($step, 'setReachable(true)'));
assert(str_contains($step, 'setCurrent(true)'));

assert(str_contains($provider, 'implements CheckoutProcessProviderInterface'));
assert(str_contains($provider, 'public function isEnabled(): bool'));
assert(str_contains($provider, 'public function buildCheckoutProcess('));
assert(str_contains($provider, 'TranslatorComponent $translator'));
assert(str_contains($provider, '$this->processBuilder->build($this->context, $session, $translator)'));

assert(str_contains($services, 'Jzvikas\\OnePageCheckout\\Integration\\CheckoutProcessBuilder:'));
assert(str_contains($services, 'Jzvikas\\OnePageCheckout\\Integration\\LegacyCheckoutRenderAdapter:'));
assert(str_contains($services, 'Jzvikas\\OnePageCheckout\\Integration\\CheckoutFrontendAssetRegistrar:'));

echo "CheckoutVersionedProcessAdapterContractSmokeTest OK\n";

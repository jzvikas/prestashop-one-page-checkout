<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$rootServices = file_get_contents($root . '/config/services.yml');
$frontServices = file_get_contents($root . '/config/front/services.yml');
$commonServices = file_get_contents($root . '/config/common/services.yml');
$runtimeContract = file_get_contents($root . '/tests/Runtime/ModuleFrontCheckoutSessionContract.php');

assert(is_string($rootServices));
assert(is_string($frontServices));
assert(is_string($commonServices));
assert(is_string($runtimeContract));

assert(str_contains($rootServices, 'resource: common/services.yml'));
assert(str_contains($frontServices, 'resource: ../common/services.yml'));

foreach ([
    'CheckoutProcessBuilder:',
    'LegacyCheckoutRenderAdapter:',
    'CheckoutFrontendAssetRegistrar:',
    'CheckoutAddressSelectionMutation:',
    'CheckoutPaymentSelectionMutation:',
    'CheckoutAgreementSelectionMutation:',
    'CheckoutMutationResponseMapper:',
] as $publicEntryService) {
    assert(str_contains($commonServices, $publicEntryService));
}

assert(substr_count($commonServices, 'public: true') >= 7);
assert(str_contains($commonServices, '@doctrine.dbal.default_connection'));

// ADR-0011 keeps helper dependencies private. The installed runtime contract must resolve a real
// public module-front entry and prove its autowired private CheckoutSession provider works without
// turning the provider interface into a Module::get() entry point.
assert(str_contains($commonServices, 'CheckoutSessionProviderInterface:'));
assert(!str_contains($runtimeContract, '$module->get(CheckoutSessionProviderInterface::class)'));
assert(str_contains($runtimeContract, '$module->get(CheckoutAddressSelectionMutation::class)'));
assert(str_contains($runtimeContract, "'checkoutSessionProvider'"));
assert(str_contains($runtimeContract, '$provider->get($context)'));

echo "CheckoutFrontServiceContainerContractSmokeTest OK\n";

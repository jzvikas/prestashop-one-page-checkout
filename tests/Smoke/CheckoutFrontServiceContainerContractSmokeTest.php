<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$rootServices = file_get_contents($root . '/config/services.yml');
$frontServices = file_get_contents($root . '/config/front/services.yml');
$commonServices = file_get_contents($root . '/config/common/services.yml');

assert(is_string($rootServices));
assert(is_string($frontServices));
assert(is_string($commonServices));

assert(str_contains($rootServices, 'resource: common/services.yml'));
assert(str_contains($frontServices, 'resource: ../common/services.yml'));

foreach ([
    'CheckoutProcessBuilder:',
    'LegacyCheckoutRenderAdapter:',
    'CheckoutFrontendAssetRegistrar:',
    'CheckoutPaymentSelectionMutation:',
    'CheckoutAgreementSelectionMutation:',
    'CheckoutMutationResponseMapper:',
] as $publicEntryService) {
    assert(str_contains($commonServices, $publicEntryService));
}

assert(substr_count($commonServices, 'public: true') >= 6);
assert(str_contains($commonServices, "@doctrine.dbal.default_connection"));

echo "CheckoutFrontServiceContainerContractSmokeTest OK\n";

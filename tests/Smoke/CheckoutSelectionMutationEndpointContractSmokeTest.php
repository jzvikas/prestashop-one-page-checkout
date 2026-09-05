<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$identityController = file_get_contents($root . '/controllers/front/identity.php');
$addressController = file_get_contents($root . '/controllers/front/addressselection.php');
$paymentController = file_get_contents($root . '/controllers/front/paymentselection.php');
$agreementController = file_get_contents($root . '/controllers/front/agreements.php');
$abstractMutation = file_get_contents($root . '/controllers/front/AbstractJzOpcMutationFrontController.php');
$identityMutation = file_get_contents($root . '/src/Checkout/Mutation/CheckoutIdentityMutation.php');
$addressMutation = file_get_contents($root . '/src/Checkout/Mutation/CheckoutAddressSelectionMutation.php');
$paymentMutation = file_get_contents($root . '/src/Checkout/Mutation/CheckoutPaymentSelectionMutation.php');
$agreementMutation = file_get_contents($root . '/src/Checkout/Mutation/CheckoutAgreementSelectionMutation.php');
$mapper = file_get_contents($root . '/src/Http/CheckoutMutationResponseMapper.php');
$services = file_get_contents($root . '/config/common/services.yml');

function assertEndpointContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

foreach ([$identityController, $addressController, $paymentController, $agreementController, $abstractMutation, $identityMutation, $addressMutation, $paymentMutation, $agreementMutation, $mapper, $services] as $source) {
    assertEndpointContract(is_string($source), 'required endpoint contract source file must be readable');
}

assertEndpointContract(str_contains($abstractMutation, 'isCustomCheckoutActive'), 'shared activation gate must remain mandatory');
assertEndpointContract(
    strpos($abstractMutation, 'REQUEST_METHOD') < strpos($abstractMutation, 'isCustomCheckoutActive'),
    'mutation controllers must reject non-POST requests before activation work',
);

assertEndpointContract(str_contains($identityController, 'Tools::getAllValues()'), 'identity controller must collect the standard request payload');
assertEndpointContract(str_contains($identityController, 'CheckoutIdentityMutation::class'), 'identity controller must delegate to guarded mutation service');
assertEndpointContract(str_contains($identityController, 'CheckoutMutationExecutionStatus::Completed'), 'identity token rotation must be limited to a completed guarded execution');
assertEndpointContract(str_contains($identityController, 'Tools::getToken(false)'), 'identity transition must refresh the Core front token after customer binding');
assertEndpointContract(str_contains($addressController, 'CheckoutAddressSelectionMutation::class'), 'address controller must delegate to guarded mutation service');
assertEndpointContract(str_contains($paymentController, 'CheckoutPaymentSelectionMutation::class'), 'payment controller must delegate to guarded mutation service');
assertEndpointContract(str_contains($agreementController, 'CheckoutAgreementSelectionMutation::class'), 'agreement controller must delegate to guarded mutation service');

assertEndpointContract(str_contains($identityMutation, 'CheckoutMutation::IdentityUpdated'), 'identity mutation must refresh the complete identity dependency graph');
assertEndpointContract(str_contains($identityMutation, '$this->identityService->submit($context, $request)'), 'Core identity submission must occur inside the orchestrated handler');
assertEndpointContract(str_contains($identityMutation, 'new CheckoutServerSelections()'), 'successful identity binding must invalidate previous payment/agreement authority');
assertEndpointContract(str_contains($addressMutation, 'CheckoutMutation::AddressSelectionUpdated'), 'address mutation must use the atomic address dependency graph');
assertEndpointContract(str_contains($addressMutation, '$this->parser->parse($request)'), 'address request parsing must occur inside orchestrated handler');
assertEndpointContract(str_contains($addressMutation, '$this->addressSelectionService->apply'), 'address authorization/Core mutation must occur inside orchestrated handler');
assertEndpointContract(str_contains($addressMutation, 'new CheckoutServerSelections()'), 'changed address must invalidate previous payment/agreement authority');
assertEndpointContract(str_contains($addressMutation, '$this->rendererRegistry->render($context, $requiredSections, $nextSelections)'), 'address mutation must return complete authoritative refresh set');

assertEndpointContract(str_contains($paymentMutation, 'CheckoutMutation::PaymentSelected'), 'payment mutation contract missing');
assertEndpointContract(str_contains($paymentMutation, '$this->paymentSelectionService->validate'), 'payment selection must be freshly validated');
assertEndpointContract(str_contains($agreementMutation, 'CheckoutMutation::AgreementsChanged'), 'agreement mutation contract missing');
assertEndpointContract(str_contains($agreementMutation, '$this->agreementSelectionService->validate'), 'agreement selection must be freshly validated');
assertEndpointContract(str_contains($mapper, "\$body['csrfToken'] = \$freshCsrfToken"), 'completed identity responses must be able to rotate the trusted browser CSRF token');

foreach ([
    'CheckoutIdentityMutation',
    'CheckoutAddressSelectionMutation',
    'CheckoutPaymentSelectionMutation',
    'CheckoutAgreementSelectionMutation',
] as $publicMutation) {
    assertEndpointContract(
        str_contains($services, "Jzvikas\\OnePageCheckout\\Checkout\\Mutation\\{$publicMutation}:\n    public: true"),
        sprintf('%s must be an intentional Module::get() entry service', $publicMutation),
    );
}
assertEndpointContract(
    str_contains($services, "Jzvikas\\OnePageCheckout\\Http\\CheckoutMutationResponseMapper:\n    public: true"),
    'response mapper must remain available to module front controllers',
);

echo "CheckoutSelectionMutationEndpointContractSmokeTest OK\n";

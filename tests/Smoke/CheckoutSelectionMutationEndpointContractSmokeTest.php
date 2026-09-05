<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$addressController = file_get_contents($root . '/controllers/front/addressselection.php');
$paymentController = file_get_contents($root . '/controllers/front/paymentselection.php');
$agreementController = file_get_contents($root . '/controllers/front/agreements.php');
$abstractMutation = file_get_contents($root . '/controllers/front/AbstractJzOpcMutationFrontController.php');
$addressMutation = file_get_contents($root . '/src/Checkout/Mutation/CheckoutAddressSelectionMutation.php');
$paymentMutation = file_get_contents($root . '/src/Checkout/Mutation/CheckoutPaymentSelectionMutation.php');
$agreementMutation = file_get_contents($root . '/src/Checkout/Mutation/CheckoutAgreementSelectionMutation.php');
$services = file_get_contents($root . '/config/common/services.yml');

function assertEndpointContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

foreach ([$addressController, $paymentController, $agreementController, $abstractMutation, $addressMutation, $paymentMutation, $agreementMutation, $services] as $source) {
    assertEndpointContract(is_string($source), 'required endpoint contract source file must be readable');
}

assertEndpointContract(str_contains($abstractMutation, 'isCustomCheckoutActive'), 'shared activation gate must remain mandatory');
assertEndpointContract(
    strpos($abstractMutation, 'REQUEST_METHOD') < strpos($abstractMutation, 'isCustomCheckoutActive'),
    'mutation controllers must reject non-POST requests before activation work',
);

assertEndpointContract(str_contains($addressController, 'Tools::getAllValues()'), 'address controller must collect the standard request payload');
assertEndpointContract(str_contains($addressController, 'CheckoutAddressSelectionMutation::class'), 'address controller must delegate to guarded mutation service');
assertEndpointContract(str_contains($paymentController, 'CheckoutPaymentSelectionMutation::class'), 'payment controller must delegate to guarded mutation service');
assertEndpointContract(str_contains($agreementController, 'CheckoutAgreementSelectionMutation::class'), 'agreement controller must delegate to guarded mutation service');

assertEndpointContract(str_contains($addressMutation, 'CheckoutMutation::AddressSelectionUpdated'), 'address mutation must use the atomic address dependency graph');
assertEndpointContract(str_contains($addressMutation, '$this->parser->parse($request)'), 'address request parsing must occur inside orchestrated handler');
assertEndpointContract(str_contains($addressMutation, '$this->addressSelectionService->apply'), 'address authorization/Core mutation must occur inside orchestrated handler');
assertEndpointContract(str_contains($addressMutation, 'new CheckoutServerSelections()'), 'changed address must invalidate previous payment/agreement authority');
assertEndpointContract(str_contains($addressMutation, '$this->rendererRegistry->render($context, $requiredSections, $nextSelections)'), 'address mutation must return complete authoritative refresh set');

assertEndpointContract(str_contains($paymentMutation, 'CheckoutMutation::PaymentSelected'), 'payment mutation contract missing');
assertEndpointContract(str_contains($paymentMutation, '$this->paymentSelectionService->validate'), 'payment selection must be freshly validated');
assertEndpointContract(str_contains($agreementMutation, 'CheckoutMutation::AgreementsChanged'), 'agreement mutation contract missing');
assertEndpointContract(str_contains($agreementMutation, '$this->agreementSelectionService->validate'), 'agreement selection must be freshly validated');

assertEndpointContract(
    str_contains($services, "Jzvikas\\OnePageCheckout\\Checkout\\Mutation\\CheckoutAddressSelectionMutation:\n    public: true"),
    'address mutation must be an intentional Module::get() entry service',
);
assertEndpointContract(
    str_contains($services, "Jzvikas\\OnePageCheckout\\Checkout\\Mutation\\CheckoutPaymentSelectionMutation:\n    public: true"),
    'payment mutation must remain public for module front controller entry',
);
assertEndpointContract(
    str_contains($services, "Jzvikas\\OnePageCheckout\\Checkout\\Mutation\\CheckoutAgreementSelectionMutation:\n    public: true"),
    'agreement mutation must remain public for module front controller entry',
);
assertEndpointContract(
    str_contains($services, "Jzvikas\\OnePageCheckout\\Http\\CheckoutMutationResponseMapper:\n    public: true"),
    'response mapper must remain available to module front controllers',
);

echo "CheckoutSelectionMutationEndpointContractSmokeTest OK\n";

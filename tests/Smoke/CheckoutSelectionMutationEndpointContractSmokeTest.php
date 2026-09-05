<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$paymentController = file_get_contents($root . '/controllers/front/paymentselection.php');
$agreementController = file_get_contents($root . '/controllers/front/agreements.php');
$abstractMutation = file_get_contents($root . '/controllers/front/AbstractJzOpcMutationFrontController.php');
$paymentMutation = file_get_contents($root . '/src/Checkout/Mutation/CheckoutPaymentSelectionMutation.php');
$agreementMutation = file_get_contents($root . '/src/Checkout/Mutation/CheckoutAgreementSelectionMutation.php');
$services = file_get_contents($root . '/config/services.yml');

foreach ([$paymentController, $agreementController, $abstractMutation, $paymentMutation, $agreementMutation, $services] as $source) {
    assert(is_string($source));
}

assert(str_contains($abstractMutation, 'isCustomCheckoutActive'));
assert(strpos($abstractMutation, 'REQUEST_METHOD') < strpos($abstractMutation, 'isCustomCheckoutActive'));
assert(str_contains($paymentController, 'Tools::getAllValues()'));
assert(str_contains($paymentController, 'CheckoutPaymentSelectionMutation::class'));
assert(str_contains($agreementController, 'Tools::getAllValues()'));
assert(str_contains($agreementController, 'CheckoutAgreementSelectionMutation::class'));

assert(str_contains($paymentMutation, 'CheckoutMutation::PaymentSelected'));
assert(str_contains($paymentMutation, '$this->parser->parse($request)'));
assert(str_contains($paymentMutation, '$this->paymentSelectionService->validate'));
assert(str_contains($paymentMutation, '$this->agreementSelectionService->validate'));
assert(str_contains($paymentMutation, '$this->rendererRegistry->render($context, $requiredSections, $nextSelections)'));

assert(str_contains($agreementMutation, 'CheckoutMutation::AgreementsChanged'));
assert(str_contains($agreementMutation, '$this->parser->parse($request)'));
assert(str_contains($agreementMutation, '$this->agreementSelectionService->validate'));
assert(str_contains($agreementMutation, '$this->rendererRegistry->render($context, $requiredSections, $nextSelections)'));

assert(str_contains($services, "Jzvikas\\OnePageCheckout\\Checkout\\Mutation\\CheckoutPaymentSelectionMutation:\n    public: true"));
assert(str_contains($services, "Jzvikas\\OnePageCheckout\\Checkout\\Mutation\\CheckoutAgreementSelectionMutation:\n    public: true"));
assert(str_contains($services, "Jzvikas\\OnePageCheckout\\Http\\CheckoutMutationResponseMapper:\n    public: true"));

echo "CheckoutSelectionMutationEndpointContractSmokeTest OK\n";

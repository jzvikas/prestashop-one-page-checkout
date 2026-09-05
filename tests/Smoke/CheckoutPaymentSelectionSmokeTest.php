<?php

declare(strict_types=1);

class Context
{
}

require_once dirname(__DIR__) . '/bootstrap.php';

use Jzvikas\OnePageCheckout\Checkout\CheckoutServerSelections;
use Jzvikas\OnePageCheckout\Checkout\Payment\CheckoutPaymentSelectionException;
use Jzvikas\OnePageCheckout\Checkout\Payment\CheckoutPaymentSelectionParser;
use Jzvikas\OnePageCheckout\Checkout\Payment\CheckoutPaymentSelectionService;
use Jzvikas\OnePageCheckout\Checkout\Rendering\CheckoutPaymentOptionsPresenterInterface;

$parser = new CheckoutPaymentSelectionParser();
$selection = $parser->parse([
    'paymentOptionId' => 'payment-option-2',
    'paymentModule' => 'demo_pay',
]);
assert($selection->optionId === 'payment-option-2');
assert($selection->moduleName === 'demo_pay');
assert($selection->stateKey() === 'demo_pay:payment-option-2');

foreach ([
    ['paymentOptionId' => '', 'paymentModule' => 'demo_pay'],
    ['paymentOptionId' => 'payment option 2', 'paymentModule' => 'demo_pay'],
    ['paymentOptionId' => 'payment-option-2', 'paymentModule' => '../demo'],
] as $invalidRequest) {
    try {
        $parser->parse($invalidRequest);
        assert(false, 'Invalid payment request should have been rejected.');
    } catch (CheckoutPaymentSelectionException) {
    }
}

$presenter = new class implements CheckoutPaymentOptionsPresenterInterface {
    public int $calls = 0;

    public function present(\Context $context): array
    {
        ++$this->calls;

        return [
            'isFree' => false,
            'paymentOptions' => [
                'demo_pay' => [
                    [
                        'id' => 'payment-option-1',
                        'module_name' => 'demo_pay',
                    ],
                    [
                        'id' => 'payment-option-2',
                        'module_name' => 'demo_pay',
                    ],
                ],
            ],
            'hookDisplayPaymentTop' => '',
        ];
    }
};

$service = new CheckoutPaymentSelectionService($presenter);
$validated = $service->validate(new Context(), $selection);
assert($presenter->calls === 1);
assert($validated->stateKey() === 'demo_pay:payment-option-2');

$currentSelections = new CheckoutServerSelections(null, ['terms', 'privacy']);
$merged = $service->mergeIntoServerSelections($validated, $currentSelections);
assert($merged->selectedPaymentOption === 'demo_pay:payment-option-2');
assert($merged->approvedAgreementKeys === ['privacy', 'terms']);

foreach ([
    ['paymentOptionId' => 'payment-option-9', 'paymentModule' => 'demo_pay'],
    ['paymentOptionId' => 'payment-option-2', 'paymentModule' => 'other_pay'],
] as $forgedRequest) {
    try {
        $service->validate(new Context(), $parser->parse($forgedRequest));
        assert(false, 'Forged payment selection should have been rejected.');
    } catch (CheckoutPaymentSelectionException) {
    }
}

$mismatchedPresenter = new class implements CheckoutPaymentOptionsPresenterInterface {
    public function present(\Context $context): array
    {
        return [
            'paymentOptions' => [
                'demo_pay' => [[
                    'id' => 'payment-option-2',
                    'module_name' => 'other_pay',
                ]],
            ],
        ];
    }
};

try {
    (new CheckoutPaymentSelectionService($mismatchedPresenter))->validate(new Context(), $selection);
    assert(false, 'Option/module mismatch should have been rejected.');
} catch (CheckoutPaymentSelectionException) {
}

echo "CheckoutPaymentSelectionSmokeTest OK\n";

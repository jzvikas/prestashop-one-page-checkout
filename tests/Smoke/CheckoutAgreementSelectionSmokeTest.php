<?php

declare(strict_types=1);

class Context {}

require_once dirname(__DIR__) . '/bootstrap.php';

use Jzvikas\OnePageCheckout\Checkout\Agreements\CheckoutAgreementSelectionException;
use Jzvikas\OnePageCheckout\Checkout\Agreements\CheckoutAgreementSelectionParser;
use Jzvikas\OnePageCheckout\Checkout\Agreements\CheckoutAgreementSelectionService;
use Jzvikas\OnePageCheckout\Checkout\CheckoutServerSelections;
use Jzvikas\OnePageCheckout\Checkout\Rendering\CheckoutAgreementsPresenterInterface;

$parser = new CheckoutAgreementSelectionParser();
assert($parser->parse(['agreements' => ['privacy', 'terms-and-conditions', 'privacy']]) === ['privacy', 'terms-and-conditions']);

$thrown = false;
try {
    $parser->parse(['agreements' => ['bad key']]);
} catch (CheckoutAgreementSelectionException) {
    $thrown = true;
}
assert($thrown);

$presenter = new class implements CheckoutAgreementsPresenterInterface {
    public function present(Context $context): array
    {
        return ['conditions' => [
            'terms-and-conditions' => '<a>TOS</a>',
            'privacy' => 'Privacy',
        ]];
    }
};

$service = new CheckoutAgreementSelectionService($presenter);
$validated = $service->validate(new Context(), ['terms-and-conditions', 'privacy']);
assert($validated === ['privacy', 'terms-and-conditions']);

$thrown = false;
try {
    $service->validate(new Context(), ['terms-and-conditions']);
} catch (CheckoutAgreementSelectionException) {
    $thrown = true;
}
assert($thrown);

$merged = $service->mergeIntoServerSelections(
    $validated,
    new CheckoutServerSelections('demo:payment-option-1'),
);
assert($merged->selectedPaymentOption === 'demo:payment-option-1');
assert($merged->approvedAgreementKeys === ['privacy', 'terms-and-conditions']);

echo "CheckoutAgreementSelectionSmokeTest OK\n";

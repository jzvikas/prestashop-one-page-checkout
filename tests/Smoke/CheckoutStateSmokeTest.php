<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Jzvikas\OnePageCheckout\Checkout\CheckoutError;
use Jzvikas\OnePageCheckout\Checkout\CheckoutMutation;
use Jzvikas\OnePageCheckout\Checkout\CheckoutRefreshResult;
use Jzvikas\OnePageCheckout\Checkout\CheckoutSection;
use Jzvikas\OnePageCheckout\Checkout\CheckoutSectionDependencyResolver;
use Jzvikas\OnePageCheckout\Checkout\CheckoutState;
use Jzvikas\OnePageCheckout\Checkout\CheckoutStateVersioner;
use Jzvikas\OnePageCheckout\Checkout\StaleCheckoutStateGuard;

$assertSame = static function (mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        fwrite(STDERR, sprintf("%s\nExpected: %s\nActual: %s\n", $message, var_export($expected, true), var_export($actual, true)));
        exit(1);
    }
};

$makeState = static function (?int $carrierId = 5, array $agreements = ['privacy', 'terms']): CheckoutState {
    return new CheckoutState(
        shopId: 2,
        cartId: 1001,
        customerId: 77,
        languageId: 1,
        currencyId: 1,
        deliveryAddressId: 301,
        invoiceAddressId: 302,
        carrierId: $carrierId,
        selectedPaymentOption: 'ps_wirepayment',
        approvedAgreementKeys: $agreements,
        cartFingerprint: 'cart:abc',
        totalsFingerprint: 'totals:def',
    );
};

$versioner = new CheckoutStateVersioner();
$versionA = $versioner->version($makeState(5, ['terms', 'privacy']));
$versionB = $versioner->version($makeState(5, ['privacy', 'terms']));
$assertSame($versionA, $versionB, 'Equivalent agreement sets must produce a stable canonical state version.');

$versionChanged = $versioner->version($makeState(6));
$assertSame(false, $versionA === $versionChanged, 'A carrier change must change the state version.');

$guard = new StaleCheckoutStateGuard($versioner);
$assertSame(true, $guard->matches($versionA, $makeState()), 'Current client state version must be accepted.');
$assertSame(false, $guard->matches('v1:stale', $makeState()), 'Stale client state version must be rejected.');
$assertSame(false, $guard->matches(null, $makeState()), 'Missing client state version must fail closed.');

$resolver = new CheckoutSectionDependencyResolver();
$values = static fn (array $sections): array => array_map(
    static fn (CheckoutSection $section): string => $section->value,
    $sections
);

$assertSame(
    ['addresses', 'delivery', 'payment', 'agreements', 'summary'],
    $values($resolver->affectedBy(CheckoutMutation::DeliveryAddressUpdated)),
    'Delivery-address mutation must refresh every downstream dependent section.'
);
$assertSame(
    ['delivery', 'payment', 'agreements', 'summary'],
    $values($resolver->affectedBy(CheckoutMutation::CarrierSelected)),
    'Carrier selection must refresh delivery, payment eligibility, legal conditions and totals.'
);
$assertSame(
    ['identity', 'addresses', 'delivery', 'payment', 'agreements', 'summary'],
    $values($resolver->affectedBy(CheckoutMutation::FullRefresh)),
    'Full refresh must preserve canonical section ordering.'
);

$errorResult = CheckoutRefreshResult::failure(
    stateVersion: $versionA,
    errors: [new CheckoutError('stale_state', 'Checkout state changed. Refresh and try again.')],
    sections: ['summary' => '<div>server summary</div>'],
)->toArray();
$assertSame(false, $errorResult['success'], 'Failure result must serialize success=false.');
$assertSame('stale_state', $errorResult['errors'][0]['code'], 'Machine-readable error code must be preserved.');
$assertSame(null, $errorResult['redirect'], 'Failure result must never invent a redirect.');

$invalidRejected = false;
try {
    new CheckoutState(
        shopId: 1,
        cartId: 0,
        customerId: null,
        languageId: 1,
        currencyId: 1,
        deliveryAddressId: null,
        invoiceAddressId: null,
        carrierId: null,
        selectedPaymentOption: null,
        approvedAgreementKeys: [],
        cartFingerprint: 'cart',
        totalsFingerprint: 'totals',
    );
} catch (\InvalidArgumentException) {
    $invalidRejected = true;
}
$assertSame(true, $invalidRejected, 'Invalid server-side state identifiers must be rejected at construction time.');

echo "Checkout state smoke tests passed.\n";

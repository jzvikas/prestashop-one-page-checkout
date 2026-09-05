<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Jzvikas\OnePageCheckout\Integration\CheckoutBrowserBootstrap;

$bootstrap = new CheckoutBrowserBootstrap(
    cartId: 42,
    csrfToken: 'csrf-token',
    stateVersion: 'v1:abc',
    paymentUrl: 'https://shop.test/module/jzonepagecheckout/paymentselection',
    agreementsUrl: 'https://shop.test/module/jzonepagecheckout/agreements',
);

assert($bootstrap->toTemplateVariables() === [
    'cartId' => 42,
    'csrfToken' => 'csrf-token',
    'stateVersion' => 'v1:abc',
    'paymentUrl' => 'https://shop.test/module/jzonepagecheckout/paymentselection',
    'agreementsUrl' => 'https://shop.test/module/jzonepagecheckout/agreements',
]);

$rejected = 0;
foreach ([
    [0, 'token', 'version', 'payment', 'agreements'],
    [1, '', 'version', 'payment', 'agreements'],
    [1, 'token', '', 'payment', 'agreements'],
    [1, 'token', 'version', '', 'agreements'],
    [1, 'token', 'version', 'payment', ''],
] as [$cartId, $token, $version, $paymentUrl, $agreementsUrl]) {
    try {
        new CheckoutBrowserBootstrap($cartId, $token, $version, $paymentUrl, $agreementsUrl);
    } catch (\InvalidArgumentException) {
        ++$rejected;
    }
}

assert($rejected === 5);

echo "CheckoutBrowserBootstrapSmokeTest OK\n";

<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Jzvikas\OnePageCheckout\Integration\CheckoutBrowserBootstrap;

$bootstrap = new CheckoutBrowserBootstrap(
    cartId: 42,
    csrfToken: 'csrf-token',
    stateVersion: 'v1:abc',
    addressUrl: 'https://shop.test/module/jzonepagecheckout/addressselection',
    paymentUrl: 'https://shop.test/module/jzonepagecheckout/paymentselection',
    agreementsUrl: 'https://shop.test/module/jzonepagecheckout/agreements',
);

assert($bootstrap->toTemplateVariables() === [
    'cartId' => 42,
    'csrfToken' => 'csrf-token',
    'stateVersion' => 'v1:abc',
    'addressUrl' => 'https://shop.test/module/jzonepagecheckout/addressselection',
    'paymentUrl' => 'https://shop.test/module/jzonepagecheckout/paymentselection',
    'agreementsUrl' => 'https://shop.test/module/jzonepagecheckout/agreements',
]);

$rejected = 0;
foreach ([
    [0, 'token', 'version', 'address', 'payment', 'agreements'],
    [1, '', 'version', 'address', 'payment', 'agreements'],
    [1, 'token', '', 'address', 'payment', 'agreements'],
    [1, 'token', 'version', '', 'payment', 'agreements'],
    [1, 'token', 'version', 'address', '', 'agreements'],
    [1, 'token', 'version', 'address', 'payment', ''],
] as [$cartId, $token, $version, $addressUrl, $paymentUrl, $agreementsUrl]) {
    try {
        new CheckoutBrowserBootstrap($cartId, $token, $version, $addressUrl, $paymentUrl, $agreementsUrl);
    } catch (\InvalidArgumentException) {
        ++$rejected;
    }
}

assert($rejected === 6);

echo "CheckoutBrowserBootstrapSmokeTest OK\n";

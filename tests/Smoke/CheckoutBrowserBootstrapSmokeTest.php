<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Jzvikas\OnePageCheckout\Integration\CheckoutBrowserBootstrap;

function assertBootstrapContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$bootstrap = new CheckoutBrowserBootstrap(
    cartId: 42,
    csrfToken: 'csrf-token',
    stateVersion: 'v1:abc',
    addressUrl: 'https://shop.test/module/jzonepagecheckout/addressselection',
    addressSaveUrl: 'https://shop.test/module/jzonepagecheckout/addresssave',
    carrierUrl: 'https://shop.test/module/jzonepagecheckout/carrierselection',
    paymentUrl: 'https://shop.test/module/jzonepagecheckout/paymentselection',
    agreementsUrl: 'https://shop.test/module/jzonepagecheckout/agreements',
);

assertBootstrapContract($bootstrap->toTemplateVariables() === [
    'cartId' => 42,
    'csrfToken' => 'csrf-token',
    'stateVersion' => 'v1:abc',
    'addressUrl' => 'https://shop.test/module/jzonepagecheckout/addressselection',
    'addressSaveUrl' => 'https://shop.test/module/jzonepagecheckout/addresssave',
    'carrierUrl' => 'https://shop.test/module/jzonepagecheckout/carrierselection',
    'paymentUrl' => 'https://shop.test/module/jzonepagecheckout/paymentselection',
    'agreementsUrl' => 'https://shop.test/module/jzonepagecheckout/agreements',
], 'trusted bootstrap must expose the exact server-generated address mutation bindings');

$rejected = 0;
foreach ([
    [0, 'token', 'version', 'address', 'address-save', 'carrier', 'payment', 'agreements'],
    [1, '', 'version', 'address', 'address-save', 'carrier', 'payment', 'agreements'],
    [1, 'token', '', 'address', 'address-save', 'carrier', 'payment', 'agreements'],
    [1, 'token', 'version', '', 'address-save', 'carrier', 'payment', 'agreements'],
    [1, 'token', 'version', 'address', '', 'carrier', 'payment', 'agreements'],
    [1, 'token', 'version', 'address', 'address-save', '', 'payment', 'agreements'],
    [1, 'token', 'version', 'address', 'address-save', 'carrier', '', 'agreements'],
    [1, 'token', 'version', 'address', 'address-save', 'carrier', 'payment', ''],
] as [$cartId, $token, $version, $addressUrl, $addressSaveUrl, $carrierUrl, $paymentUrl, $agreementsUrl]) {
    try {
        new CheckoutBrowserBootstrap($cartId, $token, $version, $addressUrl, $addressSaveUrl, $carrierUrl, $paymentUrl, $agreementsUrl);
    } catch (\InvalidArgumentException) {
        ++$rejected;
    }
}

assertBootstrapContract($rejected === 8, 'every incomplete trusted bootstrap field must fail closed');

echo "CheckoutBrowserBootstrapSmokeTest OK\n";

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
    identityUrl: 'https://shop.test/module/jzonepagecheckout/identity',
    addressUrl: 'https://shop.test/module/jzonepagecheckout/addressselection',
    addressSaveUrl: 'https://shop.test/module/jzonepagecheckout/addresssave',
    carrierUrl: 'https://shop.test/module/jzonepagecheckout/carrierselection',
    paymentUrl: 'https://shop.test/module/jzonepagecheckout/paymentselection',
    agreementsUrl: 'https://shop.test/module/jzonepagecheckout/agreements',
    finalizationUrl: 'https://shop.test/module/jzonepagecheckout/finalize',
);

assertBootstrapContract($bootstrap->toTemplateVariables() === [
    'cartId' => 42,
    'csrfToken' => 'csrf-token',
    'stateVersion' => 'v1:abc',
    'identityUrl' => 'https://shop.test/module/jzonepagecheckout/identity',
    'addressUrl' => 'https://shop.test/module/jzonepagecheckout/addressselection',
    'addressSaveUrl' => 'https://shop.test/module/jzonepagecheckout/addresssave',
    'carrierUrl' => 'https://shop.test/module/jzonepagecheckout/carrierselection',
    'paymentUrl' => 'https://shop.test/module/jzonepagecheckout/paymentselection',
    'agreementsUrl' => 'https://shop.test/module/jzonepagecheckout/agreements',
    'finalizationUrl' => 'https://shop.test/module/jzonepagecheckout/finalize',
], 'trusted bootstrap must expose the exact server-generated checkout mutation bindings');

$rejected = 0;
foreach ([
    [0, 'token', 'version', 'identity', 'address', 'address-save', 'carrier', 'payment', 'agreements', 'finalize'],
    [1, '', 'version', 'identity', 'address', 'address-save', 'carrier', 'payment', 'agreements', 'finalize'],
    [1, 'token', '', 'identity', 'address', 'address-save', 'carrier', 'payment', 'agreements', 'finalize'],
    [1, 'token', 'version', '', 'address', 'address-save', 'carrier', 'payment', 'agreements', 'finalize'],
    [1, 'token', 'version', 'identity', '', 'address-save', 'carrier', 'payment', 'agreements', 'finalize'],
    [1, 'token', 'version', 'identity', 'address', '', 'carrier', 'payment', 'agreements', 'finalize'],
    [1, 'token', 'version', 'identity', 'address', 'address-save', '', 'payment', 'agreements', 'finalize'],
    [1, 'token', 'version', 'identity', 'address', 'address-save', 'carrier', '', 'agreements', 'finalize'],
    [1, 'token', 'version', 'identity', 'address', 'address-save', 'carrier', 'payment', '', 'finalize'],
    [1, 'token', 'version', 'identity', 'address', 'address-save', 'carrier', 'payment', 'agreements', ''],
] as [$cartId, $token, $version, $identityUrl, $addressUrl, $addressSaveUrl, $carrierUrl, $paymentUrl, $agreementsUrl, $finalizationUrl]) {
    try {
        new CheckoutBrowserBootstrap(
            $cartId,
            $token,
            $version,
            $identityUrl,
            $addressUrl,
            $addressSaveUrl,
            $carrierUrl,
            $paymentUrl,
            $agreementsUrl,
            $finalizationUrl,
        );
    } catch (\InvalidArgumentException) {
        ++$rejected;
    }
}

assertBootstrapContract($rejected === 10, 'every incomplete trusted bootstrap field must fail closed');

echo "CheckoutBrowserBootstrapSmokeTest OK\n";

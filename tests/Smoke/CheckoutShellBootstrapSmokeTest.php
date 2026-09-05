<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Jzvikas\OnePageCheckout\Integration\CheckoutShellBootstrap;

$bootstrap = new CheckoutShellBootstrap(
    cartId: 42,
    csrfToken: 'csrf-token',
    stateVersion: 'v1:' . str_repeat('a', 64),
    paymentSelectionUrl: 'https://shop.example/module/jzonepagecheckout/paymentselection',
    agreementsUrl: 'https://shop.example/module/jzonepagecheckout/agreements',
);

assert($bootstrap->toTemplateData()['cartId'] === 42);
assert($bootstrap->toTemplateData()['csrfToken'] === 'csrf-token');
assert($bootstrap->toTemplateData()['stateVersion'] === 'v1:' . str_repeat('a', 64));

$invalidCases = [
    fn () => new CheckoutShellBootstrap(0, 'token', 'version', 'https://shop.example/payment', 'https://shop.example/agreements'),
    fn () => new CheckoutShellBootstrap(1, '', 'version', 'https://shop.example/payment', 'https://shop.example/agreements'),
    fn () => new CheckoutShellBootstrap(1, 'token', '', 'https://shop.example/payment', 'https://shop.example/agreements'),
    fn () => new CheckoutShellBootstrap(1, 'token', 'version', 'javascript:alert(1)', 'https://shop.example/agreements'),
    fn () => new CheckoutShellBootstrap(1, "token\nleak", 'version', 'https://shop.example/payment', 'https://shop.example/agreements'),
];

foreach ($invalidCases as $case) {
    $thrown = false;
    try {
        $case();
    } catch (InvalidArgumentException) {
        $thrown = true;
    }

    assert($thrown, 'Invalid checkout bootstrap input must fail closed.');
}

$template = file_get_contents(dirname(__DIR__, 2) . '/views/templates/front/checkout-shell.tpl');
assert(is_string($template) && $template !== '');
assert(str_contains($template, 'data-jzopc-checkout'));
assert(str_contains($template, 'data-jzopc-cart-id'));
assert(str_contains($template, 'data-jzopc-csrf-token'));
assert(str_contains($template, 'data-jzopc-state-version'));
assert(str_contains($template, 'data-jzopc-payment-url'));
assert(str_contains($template, 'data-jzopc-agreements-url'));
assert(str_contains($template, '{$jzopcSections.payment nofilter}'));
assert(str_contains($template, '{$jzopcSections.summary nofilter}'));

$rendererSource = file_get_contents(dirname(__DIR__, 2) . '/src/Integration/CheckoutShellRenderer.php');
assert(is_string($rendererSource) && $rendererSource !== '');
assert(str_contains($rendererSource, 'CheckoutSection::Addresses'));
assert(str_contains($rendererSource, 'CheckoutSection::Delivery'));
assert(str_contains($rendererSource, 'CheckoutSection::Payment'));
assert(str_contains($rendererSource, 'CheckoutSection::Agreements'));
assert(str_contains($rendererSource, 'CheckoutSection::Summary'));
assert(!str_contains($rendererSource, 'CheckoutSection::Identity,'), 'Identity must remain fail-closed until implemented.');

echo "CheckoutShellBootstrapSmokeTest OK\n";

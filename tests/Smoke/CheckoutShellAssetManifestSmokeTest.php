<?php

declare(strict_types=1);

if (!defined('_MODULE_DIR_')) {
    define('_MODULE_DIR_', '/shop/modules/');
}

require_once dirname(__DIR__, 2) . '/src/Integration/CheckoutFrontendAssetRegistrar.php';

use Jzvikas\OnePageCheckout\Integration\CheckoutFrontendAssetRegistrar;

function assertShellAssetManifest(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$assets = new CheckoutFrontendAssetRegistrar();
$stylesheets = $assets->shellStylesheetUrls();
$expectedStylesheets = [
    '/shop/modules/jzonepagecheckout/views/css/checkout.css',
];
assertShellAssetManifest($stylesheets === $expectedStylesheets, 'shell stylesheet manifest must preserve exact PrestaShop base URI');

$urls = $assets->shellJavascriptUrls();
$expected = [
    '/shop/modules/jzonepagecheckout/views/js/payment-controller.js',
    '/shop/modules/jzonepagecheckout/views/js/checkout-mutation-client.js',
    '/shop/modules/jzonepagecheckout/views/js/final-submit-controller.js',
    '/shop/modules/jzonepagecheckout/views/js/ordinary-payment-submit-guard.js',
    '/shop/modules/jzonepagecheckout/views/js/binary-payment-controller.js',
    '/shop/modules/jzonepagecheckout/views/js/payment-handoff-ambiguity-guard.js',
];

assertShellAssetManifest($urls === $expected, 'shell runtime manifest must preserve exact order and PrestaShop base URI');
$allAssets = array_merge($stylesheets, $urls);
assertShellAssetManifest(count(array_unique($allAssets)) === count($allAssets), 'shell asset manifests must not contain duplicate asset URLs');

foreach ($allAssets as $url) {
    assertShellAssetManifest(str_starts_with($url, '/shop/modules/jzonepagecheckout/views/'), 'shell asset URL escaped the module-owned path');
    assertShellAssetManifest(!str_contains($url, '..'), 'shell asset URL must not contain path traversal segments');
}

fwrite(STDOUT, "Checkout shell asset manifest smoke test passed.\n");

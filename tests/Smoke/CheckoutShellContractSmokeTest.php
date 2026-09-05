<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$shell = file_get_contents($root . '/views/templates/front/checkout-shell.tpl');
$renderer = file_get_contents($root . '/src/Integration/CheckoutShellRenderer.php');
$factory = file_get_contents($root . '/src/Integration/CheckoutBrowserBootstrapFactory.php');
$assets = file_get_contents($root . '/src/Integration/CheckoutFrontendAssetRegistrar.php');
$module = file_get_contents($root . '/jzonepagecheckout.php');

function assertShellContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

assertShellContract(is_string($shell) && str_contains($shell, 'data-jzopc-checkout'), 'trusted checkout root is required');
assertShellContract(str_contains($shell, 'data-jzopc-cart-id'), 'cart bootstrap is required');
assertShellContract(str_contains($shell, 'data-jzopc-state-version'), 'state version bootstrap is required');
assertShellContract(str_contains($shell, 'data-jzopc-csrf-token'), 'CSRF bootstrap is required');
assertShellContract(str_contains($shell, 'data-jzopc-address-url'), 'address endpoint bootstrap is required');
assertShellContract(str_contains($shell, 'data-jzopc-address-save-url'), 'address-save endpoint bootstrap is required');
assertShellContract(str_contains($shell, 'data-jzopc-carrier-url'), 'carrier endpoint bootstrap is required');
assertShellContract(str_contains($shell, 'data-jzopc-payment-url'), 'payment endpoint bootstrap is required');
assertShellContract(str_contains($shell, 'data-jzopc-agreements-url'), 'agreements endpoint bootstrap is required');
assertShellContract(str_contains($shell, "|escape:'htmlall':'UTF-8'"), 'bootstrap attributes must remain escaped');
assertShellContract(str_contains($shell, '{$jzopc_section_html nofilter}'), 'trusted section HTML boundary is required');

assertShellContract(is_string($renderer) && str_contains($renderer, 'CheckoutServerSelectionsStoreInterface'), 'renderer must load canonical server selections');
assertShellContract(str_contains($renderer, 'CheckoutSection::Addresses'), 'addresses renderer is required');
assertShellContract(str_contains($renderer, 'CheckoutSection::Delivery'), 'delivery renderer is required');
assertShellContract(str_contains($renderer, 'CheckoutSection::Payment'), 'payment renderer is required');
assertShellContract(str_contains($renderer, 'CheckoutSection::Agreements'), 'agreements renderer is required');
assertShellContract(str_contains($renderer, 'CheckoutSection::Summary'), 'summary renderer is required');
assertShellContract(!str_contains($renderer, 'CheckoutSection::Identity'), 'unfinished identity renderer must not be fabricated');

assertShellContract(is_string($factory) && str_contains($factory, '\\Tools::getToken(false)'), 'bootstrap factory must use Core front CSRF token');
assertShellContract(str_contains($factory, "'addressselection'"), 'address URL must be generated server-side');
assertShellContract(str_contains($factory, "'addresssave'"), 'address-save URL must be generated server-side');
assertShellContract(str_contains($factory, "'carrierselection'"), 'carrier URL must be generated server-side');
assertShellContract(str_contains($factory, "'paymentselection'"), 'payment URL must be generated server-side');
assertShellContract(str_contains($factory, "'agreements'"), 'agreements URL must be generated server-side');
assertShellContract(str_contains($factory, 'stateVersioner->version'), 'bootstrap must derive authoritative state version');
assertShellContract(str_contains($factory, 'stateFactory->create'), 'bootstrap must derive state from Core context');

assertShellContract(is_string($assets) && str_contains($assets, 'payment-controller.js'), 'payment controller asset must remain registered');
assertShellContract(str_contains($assets, 'checkout-mutation-client.js'), 'mutation transport asset must remain registered');
assertShellContract(str_contains($assets, 'registerJavascript'), 'assets must use front controller registration API');
assertShellContract(is_string($module) && str_contains($module, 'private const INTEGRATION_SHELL_READY = false;'), 'production readiness gate must remain closed');

echo "CheckoutShellContractSmokeTest OK\n";

<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$shell = file_get_contents($root . '/views/templates/front/checkout-shell.tpl');
$renderer = file_get_contents($root . '/src/Integration/CheckoutShellRenderer.php');
$factory = file_get_contents($root . '/src/Integration/CheckoutBrowserBootstrapFactory.php');
$assets = file_get_contents($root . '/src/Integration/CheckoutFrontendAssetRegistrar.php');
$module = file_get_contents($root . '/jzonepagecheckout.php');

assert(is_string($shell) && str_contains($shell, 'data-jzopc-checkout'));
assert(str_contains($shell, 'data-jzopc-cart-id'));
assert(str_contains($shell, 'data-jzopc-state-version'));
assert(str_contains($shell, 'data-jzopc-csrf-token'));
assert(str_contains($shell, 'data-jzopc-address-url'));
assert(str_contains($shell, 'data-jzopc-payment-url'));
assert(str_contains($shell, 'data-jzopc-agreements-url'));
assert(str_contains($shell, "|escape:'htmlall':'UTF-8'"));
assert(str_contains($shell, '{$jzopc_section_html nofilter}'));

assert(is_string($renderer) && str_contains($renderer, 'CheckoutServerSelectionsStoreInterface'));
assert(str_contains($renderer, 'CheckoutSection::Addresses'));
assert(str_contains($renderer, 'CheckoutSection::Delivery'));
assert(str_contains($renderer, 'CheckoutSection::Payment'));
assert(str_contains($renderer, 'CheckoutSection::Agreements'));
assert(str_contains($renderer, 'CheckoutSection::Summary'));
assert(!str_contains($renderer, 'CheckoutSection::Identity'));

assert(is_string($factory) && str_contains($factory, '\\Tools::getToken(false)'));
assert(str_contains($factory, "'addressselection'"));
assert(str_contains($factory, "'paymentselection'"));
assert(str_contains($factory, "'agreements'"));
assert(str_contains($factory, 'stateVersioner->version'));
assert(str_contains($factory, 'stateFactory->create'));

assert(is_string($assets) && str_contains($assets, 'payment-controller.js'));
assert(str_contains($assets, 'checkout-mutation-client.js'));
assert(str_contains($assets, 'registerJavascript'));

assert(is_string($module) && str_contains($module, 'private const INTEGRATION_SHELL_READY = false;'));

echo "CheckoutShellContractSmokeTest OK\n";

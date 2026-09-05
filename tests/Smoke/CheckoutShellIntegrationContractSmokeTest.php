<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$moduleSource = file_get_contents($root . '/jzonepagecheckout.php');
$servicesSource = file_get_contents($root . '/config/services.yml');
$cssSource = file_get_contents($root . '/views/css/checkout.css');

assert(is_string($moduleSource) && $moduleSource !== '');
assert(is_string($servicesSource) && $servicesSource !== '');
assert(is_string($cssSource) && $cssSource !== '');

assert(str_contains($moduleSource, 'private const INTEGRATION_SHELL_READY = false;'));
assert(str_contains($moduleSource, 'public function hookActionFrontControllerSetMedia(): void'));
assert(str_contains($moduleSource, 'instanceof OrderController'));
assert(str_contains($moduleSource, '!$this->isCustomCheckoutActive()'));
assert(str_contains($moduleSource, 'views/js/checkout-mutation-client.js'));
assert(str_contains($moduleSource, 'views/js/payment-controller.js'));
assert(str_contains($moduleSource, 'views/css/checkout.css'));
assert(str_contains($moduleSource, "'actionFrontControllerSetMedia'"));

assert(str_contains($servicesSource, 'Jzvikas\\OnePageCheckout\\Integration\\CheckoutShellBootstrapFactory: ~'));
assert(str_contains($servicesSource, 'Jzvikas\\OnePageCheckout\\Integration\\CheckoutShellRenderer:'));
assert(str_contains($servicesSource, '    public: true'));

assert(str_contains($cssSource, '.jzopc-checkout'));
assert(!preg_match('/(^|[\s,{])(button|input|\.row|\.form-control)(?=[\s,{.:#\[])/m', $cssSource));
assert(!str_contains($cssSource, '!important'));

echo "CheckoutShellIntegrationContractSmokeTest OK\n";

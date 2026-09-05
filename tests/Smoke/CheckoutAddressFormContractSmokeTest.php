<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$service = file_get_contents($root . '/src/Checkout/Address/CheckoutAddressFormService.php');
$mutation = file_get_contents($root . '/src/Checkout/Mutation/CheckoutAddressFormMutation.php');
$controller = file_get_contents($root . '/controllers/front/addresssave.php');
$config = file_get_contents($root . '/config/common/services.yml');
$resolver = file_get_contents($root . '/src/Checkout/CheckoutSectionDependencyResolver.php');

function assertAddressFormContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

assertAddressFormContract(is_string($service), 'address form service source must be readable');
assertAddressFormContract(str_contains($service, 'new \\CustomerAddressForm('), 'address persistence must use Core CustomerAddressForm');
assertAddressFormContract(str_contains($service, 'new \\CustomerAddressPersister('), 'address persistence must use Core CustomerAddressPersister');
assertAddressFormContract(str_contains($service, 'new \\CustomerAddressFormatter('), 'address fields must come from Core CustomerAddressFormatter');
assertAddressFormContract(str_contains($service, "\\Tools::getToken(true, \$context)"), 'Core persister token must be generated server-side');
assertAddressFormContract(str_contains($service, "\$payload['token'] = \\Tools::getToken(true, \$context);"), 'browser token must not become Core address-persister authority');
assertAddressFormContract(str_contains($service, '\\Customer::customerHasAddress('), 'edit targets must be ownership checked');
assertAddressFormContract(str_contains($service, '->setIdAddressDelivery('), 'saved delivery addresses must be applied through CheckoutSession');
assertAddressFormContract(str_contains($service, '->setIdAddressInvoice('), 'saved invoice addresses must be applied through CheckoutSession');
assertAddressFormContract(!str_contains($service, '->id_address_delivery ='), 'service must not directly write cart delivery header');
assertAddressFormContract(!str_contains($service, '->id_address_invoice ='), 'service must not directly write cart invoice header');

assertAddressFormContract(is_string($mutation) && str_contains($mutation, 'CheckoutMutation::AddressBookUpdated'), 'address save must use the shared mutation orchestrator dependency graph');
assertAddressFormContract(str_contains($mutation, 'new CheckoutServerSelections()'), 'successful address save must invalidate payment/agreement authority');
assertAddressFormContract(is_string($controller) && str_contains($controller, 'extends JzOnePageCheckoutAbstractMutationModuleFrontController'), 'address save endpoint must inherit the POST/activation boundary');
assertAddressFormContract(is_string($config) && str_contains($config, 'CheckoutAddressFormMutation'), 'address save mutation must be exposed through the front service container');
assertAddressFormContract(is_string($resolver) && str_contains($resolver, 'CheckoutMutation::AddressBookUpdated'), 'address saves must refresh downstream checkout dependencies');

fwrite(STDOUT, "Checkout address form contract smoke tests passed.\n");

<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$workflow = file_get_contents($root . '/.github/workflows/prestashop-runtime.yml');
$contract = file_get_contents($root . '/tests/Runtime/ActiveCartDeliveryStateContract.php');
$browser = file_get_contents($root . '/tests/Browser/finalization-orderable-concurrent-tabs-browser-contract.mjs');
$module = file_get_contents($root . '/jzonepagecheckout.php');

function assertLiveCartDeliveryStateContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

assertLiveCartDeliveryStateContract(is_string($workflow), 'runtime workflow must be readable');
assertLiveCartDeliveryStateContract(is_string($contract), 'live-cart delivery-state contract must be readable');
assertLiveCartDeliveryStateContract(is_string($browser), 'orderable browser contract must be readable');
assertLiveCartDeliveryStateContract(is_string($module), 'module source must be readable');

assertLiveCartDeliveryStateContract(
    str_contains($contract, 'new Cart($cartId)')
        && str_contains($contract, '$cart->id_customer')
        && str_contains($contract, '$cart->id_address_delivery')
        && str_contains($contract, '$cart->id_address_invoice')
        && str_contains($contract, 'Customer::customerHasAddress('),
    'runtime diagnostic must verify persisted Core customer and address bindings on the browser-created cart'
);
assertLiveCartDeliveryStateContract(
    str_contains($contract, 'Customer::getGroupsStatic($customerId)')
        && str_contains($contract, 'Carrier::getCarriersForOrder(')
        && str_contains($contract, 'Carrier::getAvailableCarrierList(')
        && str_contains($contract, '$cart->getDeliveryOptionList($country, true)'),
    'runtime diagnostic must probe live customer groups, carrier eligibility and fresh Core delivery options'
);
assertLiveCartDeliveryStateContract(
    !str_contains($contract, 'validateOrder(')
        && !str_contains($contract, 'new Order(')
        && !str_contains($contract, 'Order::')
        && !str_contains($contract, 'setDeliveryOption(')
        && !str_contains($contract, 'delivery_option ='),
    'live-cart diagnostic must remain read-only with respect to delivery selection and Core order creation'
);
assertLiveCartDeliveryStateContract(
    str_contains($workflow, 'Execute PrestaShop 9.1 live-cart delivery-state diagnostic')
        && str_contains($workflow, 'ActiveCartDeliveryStateContract.php')
        && str_contains($workflow, 'if: always() && matrix.family == \'9.1\' && matrix.native_opc == \'0\''),
    '9.1 runtime must execute live-cart diagnostics even when the orderable Chromium gate fails first'
);
assertLiveCartDeliveryStateContract(
    str_contains($browser, 'input[name="delivery_option"]')
        && str_contains($browser, "fail('carrier-selection: orderable physical checkout has no Core delivery option.')"),
    'diagnostic instrumentation must not weaken the browser requirement for a real Core-rendered delivery option'
);
assertLiveCartDeliveryStateContract(
    str_contains($module, 'private const INTEGRATION_SHELL_READY = false;'),
    'diagnostic milestone must keep production checkout takeover closed'
);

fwrite(STDOUT, "Live-cart delivery-state runtime source checks passed.\n");
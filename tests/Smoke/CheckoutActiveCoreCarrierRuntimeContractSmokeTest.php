<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$workflow = file_get_contents($root . '/.github/workflows/prestashop-runtime.yml');
$fixture = file_get_contents($root . '/tests/Runtime/PrepareActiveCheckoutHttpFixture.php');
$contract = file_get_contents($root . '/tests/Runtime/ActiveCoreCarrierAvailabilityContract.php');
$browser = file_get_contents($root . '/tests/Browser/finalization-orderable-concurrent-tabs-browser-contract.mjs');
$module = file_get_contents($root . '/jzonepagecheckout.php');

function assertActiveCoreCarrierContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

assertActiveCoreCarrierContract(is_string($workflow), 'runtime workflow must be readable');
assertActiveCoreCarrierContract(is_string($fixture), 'active runtime fixture must be readable');
assertActiveCoreCarrierContract(is_string($contract), 'active Core carrier contract must be readable');
assertActiveCoreCarrierContract(is_string($browser), 'orderable browser contract must be readable');
assertActiveCoreCarrierContract(is_string($module), 'module source must be readable');

assertActiveCoreCarrierContract(
    str_contains($fixture, '$carrier->is_module = false;')
        && str_contains($fixture, '$carrier->external_module_name = null;')
        && str_contains($fixture, '$carrier->shipping_method = Carrier::SHIPPING_METHOD_FREE;')
        && str_contains($fixture, '$carrier->need_range = false;')
        && str_contains($fixture, '$carrier->max_width = 0;')
        && str_contains($fixture, '$carrier->max_height = 0;')
        && str_contains($fixture, '$carrier->max_depth = 0;')
        && str_contains($fixture, '$carrier->max_weight = 0.0;'),
    'runtime carrier fixture must explicitly persist deterministic non-module/free/no-range/no-limit carrier semantics'
);
assertActiveCoreCarrierContract(
    str_contains($fixture, '$carrier = new Carrier((int) $carrier->id, $languageId);')
        && str_contains($fixture, 'Validate::isLoadedObject($carrier)')
        && str_contains($fixture, '(int) $carrier->id_reference <= 0'),
    'runtime carrier fixture must reload the Core model after Carrier::add() before trusting the SQL-persisted id_reference'
);
assertActiveCoreCarrierContract(
    str_contains($fixture, '$carrier->addZone($zoneId)')
        && str_contains($fixture, "'carrier_group'")
        && str_contains($fixture, "'carrier_shop'")
        && str_contains($fixture, "Configuration::updateValue('PS_CARRIER_DEFAULT'"),
    'runtime carrier fixture must preserve Core zone/group/shop/default-carrier associations'
);
assertActiveCoreCarrierContract(
    !str_contains($fixture, '$carrier->delete();')
        && str_contains($fixture, 'failure paths must remain fail-fast instead of attempting model cleanup'),
    'standalone runtime fixture must not invoke Carrier::delete() without a booted Symfony kernel and mask the original fixture failure'
);
assertActiveCoreCarrierContract(
    str_contains($fixture, '$expectedFamily === \'9.1\'')
        && str_contains($fixture, "Module::getInstanceByName('ps_checkpayment')")
        && str_contains($fixture, "'module_carrier'")
        && str_contains($fixture, "'id_reference' => \$carrierReference")
        && str_contains($fixture, 'Db::INSERT_IGNORE'),
    '9.1 runtime fixture must add the generated post-install carrier to the official payment module Core carrier restriction without changing production OPC code'
);
assertActiveCoreCarrierContract(
    str_contains($contract, 'Carrier::getCarriersForOrder(')
        && str_contains($contract, 'Carrier::getAvailableCarrierList(')
        && str_contains($contract, "Configuration::get('PS_GUEST_GROUP')")
        && str_contains($contract, 'Carrier::checkCarrierZone('),
    'runtime contract must prove Core carrier discovery for zone, guest group and fixture product rather than trusting fixture rows alone'
);
assertActiveCoreCarrierContract(
    str_contains($contract, '$expectedFamily === \'9.1\'')
        && str_contains($contract, "Module::getInstanceByName('ps_checkpayment')")
        && str_contains($contract, "_DB_PREFIX_ . 'module_carrier`'")
        && str_contains($contract, '$paymentCarrierAssociation !== 1'),
    '9.1 carrier contract must prove the official payment fixture is allowed for the deterministic carrier before Chromium starts'
);
assertActiveCoreCarrierContract(
    str_contains($workflow, 'Execute active Core carrier availability contract')
        && str_contains($workflow, 'ActiveCoreCarrierAvailabilityContract.php')
        && str_contains($workflow, '"$JZOPC_RUNTIME_PRODUCT_ID"'),
    'installed runtime workflow must execute the Core carrier availability gate after fixture creation and before Chromium'
);
assertActiveCoreCarrierContract(
    !str_contains($contract, 'validateOrder(')
        && !str_contains($contract, 'Order::')
        && !str_contains($contract, 'delivery_option =')
        && !str_contains($contract, 'setDeliveryOption('),
    'carrier probe must not create orders or inject/persist a browser delivery selection'
);
assertActiveCoreCarrierContract(
    str_contains($browser, 'input[name="delivery_option"]')
        && str_contains($browser, "fail('carrier-selection: orderable physical checkout has no Core delivery option.')"),
    'browser gate must continue requiring a real Core-rendered delivery option and fail closed when none exists'
);
assertActiveCoreCarrierContract(
    str_contains($module, 'private const INTEGRATION_SHELL_READY = false;'),
    'carrier fixture hardening must not open production checkout takeover'
);

fwrite(STDOUT, "Active Core carrier runtime contract source checks passed.\n");
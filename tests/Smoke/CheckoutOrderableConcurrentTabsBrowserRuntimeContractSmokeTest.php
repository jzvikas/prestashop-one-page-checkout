<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$workflow = file_get_contents($root . '/.github/workflows/prestashop-runtime.yml');
$browser = file_get_contents($root . '/tests/Browser/finalization-orderable-concurrent-tabs-browser-contract.mjs');
$module = file_get_contents($root . '/jzonepagecheckout.php');

function assertOrderableConcurrentTabsBrowserContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

assertOrderableConcurrentTabsBrowserContract(is_string($workflow), 'runtime workflow must be readable');
assertOrderableConcurrentTabsBrowserContract(is_string($browser), 'orderable concurrent-tab browser contract must be readable');
assertOrderableConcurrentTabsBrowserContract(is_string($module), 'module source must be readable');

assertOrderableConcurrentTabsBrowserContract(
    str_contains($workflow, 'Install PrestaShop 9.1 check-payment browser fixture')
        && str_contains($workflow, 'git checkout 163eea350e29616f7cff343285d8c4bcc2b6cc44')
        && str_contains($workflow, 'Configuration::updateValue("PS_GUEST_CHECKOUT_ENABLED", 1)')
        && str_contains($workflow, 'Configuration::updateValue("CHEQUE_NAME", "JZ OPC Runtime Payee")'),
    '9.1 browser fixture must pin and configure the official check-payment module plus guest checkout'
);
assertOrderableConcurrentTabsBrowserContract(
    str_contains($workflow, 'Execute PrestaShop 9.1 orderable concurrent-tab finalization Chromium contract')
        && str_contains($workflow, 'node finalization-orderable-concurrent-tabs-browser-contract.mjs'),
    'runtime workflow must wire the orderable concurrent-tab browser contract'
);
assertOrderableConcurrentTabsBrowserContract(
    substr_count($workflow, "if: matrix.family == '9.1'") >= 5,
    'orderable payment fixture and browser gate must remain scoped to the PrestaShop 9.1 production milestone'
);
assertOrderableConcurrentTabsBrowserContract(
    str_contains($browser, 'completeGuestIdentity(page)')
        && str_contains($browser, 'completeDeliveryAddress(page)')
        && str_contains($browser, 'selectCarrier(page)')
        && str_contains($browser, 'selectCheckPayment(page)')
        && str_contains($browser, 'approveAgreements(page)'),
    'browser contract must prepare identity, address, carrier, payment and agreements through checkout browser mutations'
);
assertOrderableConcurrentTabsBrowserContract(
    str_contains($browser, 'deliverySectionDiagnostic(payload)')
        && str_contains($browser, "Object.prototype.hasOwnProperty.call(payload.sections, 'delivery')")
        && str_contains($browser, "hasDeliveryOption: /\\bname=(['\"])delivery_option\\1/i.test(html)")
        && str_contains($browser, "formatDeliveryDiagnostic('address_save_delivery'")
        && str_contains($browser, "formatDeliveryDiagnostic('same_address_delivery'")
        && str_contains($browser, '${label}_has_delivery_option=')
        && str_contains($browser, 'dom_delivery_options=')
        && str_contains($browser, 'mutation response contained a Core delivery_option but browser section replacement lost it')
        && str_contains($browser, 'authoritative address mutation response did not contain a Core delivery_option'),
    'browser contract must classify the address-mutation response versus DOM delivery-option boundary without dumping checkout HTML'
);
assertOrderableConcurrentTabsBrowserContract(
    !str_contains($browser, 'console.log(payload')
        && !str_contains($browser, 'JSON.stringify(payload')
        && !str_contains($browser, 'response.text()'),
    'delivery diagnostics must remain bounded and must not dump mutation payloads, tokens or address HTML'
);
assertOrderableConcurrentTabsBrowserContract(
    str_contains($browser, 'data-module-name="ps_checkpayment"')
        && str_contains($browser, "finalizationRequest(pageA, bindingA, 'begin', attemptA)")
        && str_contains($browser, "finalizationRequest(pageB, bindingB, 'begin', attemptB)")
        && str_contains($browser, "loserCodes.includes('finalization_in_progress')"),
    'browser contract must use the official payment option and prove one competing finalization is blocked'
);
assertOrderableConcurrentTabsBrowserContract(
    str_contains($browser, "'begin', winner.attempt")
        && str_contains($browser, "'release', loser.attempt")
        && str_contains($browser, "readBinding(pageA, 'tab-a-reserved', '1')")
        && str_contains($browser, "'release', winner.attempt")
        && str_contains($browser, "readBinding(pageA, 'tab-a-released', '0')"),
    'browser contract must prove exact replay idempotency, foreign release preservation and exact winning release'
);
assertOrderableConcurrentTabsBrowserContract(
    !str_contains($browser, 'validateOrder(')
        && !str_contains($browser, 'Order::')
        && !str_contains($browser, 'PaymentModule::')
        && !str_contains($browser, 'jzopc-payment__native-submit'),
    'browser contract must stop before native payment submission and must not create or validate an order'
);
assertOrderableConcurrentTabsBrowserContract(
    str_contains($module, 'private const INTEGRATION_SHELL_READY = false;'),
    'adding the orderable concurrent-tab browser gate must not open production checkout takeover'
);

fwrite(STDOUT, "Orderable concurrent-tab finalization browser contract source checks passed.\n");

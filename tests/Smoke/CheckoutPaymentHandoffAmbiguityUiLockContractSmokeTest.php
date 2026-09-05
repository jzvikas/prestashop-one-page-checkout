<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$guard = file_get_contents($root . '/views/js/payment-handoff-ambiguity-guard.js');
$shell = file_get_contents($root . '/views/templates/front/checkout-shell.tpl');
$assets = file_get_contents($root . '/src/Integration/CheckoutFrontendAssetRegistrar.php');
$ordinary = file_get_contents($root . '/views/js/final-submit-controller.js');
$binary = file_get_contents($root . '/views/js/binary-payment-controller.js');
$module = file_get_contents($root . '/jzonepagecheckout.php');

function assertPaymentHandoffAmbiguityUiLockContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

foreach ([$guard, $shell, $assets, $ordinary, $binary, $module] as $source) {
    assertPaymentHandoffAmbiguityUiLockContract(is_string($source), 'ambiguity UI lock contract source must be readable');
}

assertPaymentHandoffAmbiguityUiLockContract(
    str_contains($ordinary, "jzopc:checkout:payment-handoff-ambiguous"),
    'ordinary payment handoff must publish the ambiguity event'
);
assertPaymentHandoffAmbiguityUiLockContract(
    str_contains($binary, "jzopc:checkout:payment-handoff-ambiguous"),
    'binary payment handoff must publish the ambiguity event'
);
assertPaymentHandoffAmbiguityUiLockContract(
    str_contains($guard, "document.addEventListener('jzopc:checkout:payment-handoff-ambiguous'"),
    'a dedicated browser guard must consume the ambiguity event'
);
assertPaymentHandoffAmbiguityUiLockContract(
    str_contains($guard, "Promise.resolve().then(function ()"),
    'ambiguity lock must run after synchronous submit-controller cleanup so normal failure cleanup cannot re-enable controls'
);
assertPaymentHandoffAmbiguityUiLockContract(
    str_contains($guard, "root.setAttribute('data-jzopc-payment-handoff-ambiguous', 'true')"),
    'ambiguous checkout state must be explicitly marked in the DOM'
);
assertPaymentHandoffAmbiguityUiLockContract(
    str_contains($guard, 'control.disabled = true;'),
    'all mutable checkout controls must be disabled after ambiguous native activation'
);
assertPaymentHandoffAmbiguityUiLockContract(
    str_contains($guard, "status.setAttribute('role', 'alert')")
        && str_contains($guard, "status.setAttribute('aria-live', 'assertive')"),
    'ambiguous payment status must be announced accessibly'
);
assertPaymentHandoffAmbiguityUiLockContract(
    str_contains($shell, 'data-jzopc-final-message="handoff-ambiguous"'),
    'the ambiguity lock must use translated server-rendered customer messaging'
);
assertPaymentHandoffAmbiguityUiLockContract(
    str_contains($assets, "views/js/payment-handoff-ambiguity-guard.js"),
    'ambiguity guard must be part of the checkout asset set'
);
assertPaymentHandoffAmbiguityUiLockContract(
    !str_contains($guard, 'finalizationAction') && !str_contains($guard, 'validateOrder('),
    'ambiguity UI guard must not attempt release, payment submission or order creation'
);
assertPaymentHandoffAmbiguityUiLockContract(
    is_string($module) && str_contains($module, 'private const INTEGRATION_SHELL_READY = false;'),
    'production readiness gate must remain closed while browser/runtime evidence is pending'
);

fwrite(STDOUT, "Checkout payment handoff ambiguity UI lock contract smoke tests passed.\n");

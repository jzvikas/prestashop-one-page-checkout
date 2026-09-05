<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$guard = file_get_contents($root . '/views/js/ordinary-payment-submit-guard.js');
$finalSubmit = file_get_contents($root . '/views/js/final-submit-controller.js');
$payment = file_get_contents($root . '/views/templates/front/sections/payment.tpl');
$assets = file_get_contents($root . '/src/Integration/CheckoutFrontendAssetRegistrar.php');
$module = file_get_contents($root . '/jzonepagecheckout.php');

function assertOrdinaryPaymentSubmitGuardContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

foreach ([$guard, $finalSubmit, $payment, $assets, $module] as $source) {
    assertOrdinaryPaymentSubmitGuardContract(is_string($source), 'ordinary payment submit guard source must be readable');
}

assertOrdinaryPaymentSubmitGuardContract(
    str_contains($guard, "this.root.addEventListener('submit', this.onSubmit, true)"),
    'ordinary payment forms must be guarded in capture phase before module submit handlers',
);
assertOrdinaryPaymentSubmitGuardContract(
    str_contains($guard, "selected.classList.contains('binary')"),
    'ordinary submit guard must leave binary options to the dedicated binary controller',
);
assertOrdinaryPaymentSubmitGuardContract(
    str_contains($guard, 'event.preventDefault();') && str_contains($guard, 'event.stopImmediatePropagation();'),
    'unreserved ordinary payment submission must be stopped before third-party submit handlers run',
);
assertOrdinaryPaymentSubmitGuardContract(
    str_contains($guard, "jzopc:checkout:payment-submit-blocked"),
    'blocked direct payment submission must publish an observable lifecycle event',
);
assertOrdinaryPaymentSubmitGuardContract(
    str_contains($guard, "this.root.addEventListener('jzopc:checkout:payment-handoff', this.onPaymentHandoff)"),
    'ordinary payment submission may be authorized only at the final-submit native handoff boundary',
);
assertOrdinaryPaymentSubmitGuardContract(
    str_contains($finalSubmit, "this.dispatch('jzopc:checkout:payment-handoff', { paymentOptionId: this.paymentOptionId })"),
    'final-submit controller must emit the authorization boundary only after finalization preflight',
);
assertOrdinaryPaymentSubmitGuardContract(
    str_contains($guard, 'this.authorizedForm = form;')
        && str_contains($guard, 'this.authorizedPaymentOptionId = paymentOptionId;'),
    'authorization must bind the exact selected option and exact module-owned form',
);
assertOrdinaryPaymentSubmitGuardContract(
    str_contains($guard, 'this.authorizedForm === form')
        && str_contains($guard, 'this.authorizedPaymentOptionId === paymentOptionId')
        && str_contains($guard, 'form.isConnected'),
    'handoff authorization must fail closed after form replacement or option mismatch',
);
assertOrdinaryPaymentSubmitGuardContract(
    str_contains($guard, "this.root.addEventListener('jzopc:section:updated', this.onCheckoutStateChanged)"),
    'section replacement must revoke stale ordinary-form authorization',
);
assertOrdinaryPaymentSubmitGuardContract(
    str_contains($guard, "this.root.addEventListener('change', this.onCheckoutStateChanged)"),
    'payment option changes must revoke stale ordinary-form authorization',
);
assertOrdinaryPaymentSubmitGuardContract(
    str_contains($finalSubmit, 'paymentForm.contains(control)'),
    'third-party payment form fields must remain enabled so native successful controls and embedded integrations are preserved',
);
assertOrdinaryPaymentSubmitGuardContract(
    str_contains($payment, '{$option.form nofilter}'),
    'Core-presented third-party payment form markup must remain preserved rather than copied or rewritten',
);
assertOrdinaryPaymentSubmitGuardContract(
    str_contains($assets, 'views/js/ordinary-payment-submit-guard.js'),
    'ordinary payment submit guard must be part of the checkout asset set',
);
assertOrdinaryPaymentSubmitGuardContract(
    !str_contains($guard, 'validateOrder('),
    'ordinary browser submit guard must never create a PrestaShop order itself',
);
assertOrdinaryPaymentSubmitGuardContract(
    str_contains($module, 'private const INTEGRATION_SHELL_READY = false;'),
    'readiness gate must remain closed while browser/runtime payment verification is pending',
);

fwrite(STDOUT, "Checkout ordinary payment submit guard contract smoke tests passed.\n");

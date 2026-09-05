<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$client = file_get_contents($root . '/views/js/final-submit-controller.js');
$shell = file_get_contents($root . '/views/templates/front/checkout-shell.tpl');
$payment = file_get_contents($root . '/views/templates/front/sections/payment.tpl');
$module = file_get_contents($root . '/jzonepagecheckout.php');

function assertFinalSubmitBrowserContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

foreach ([$client, $shell, $payment, $module] as $source) {
    assertFinalSubmitBrowserContract(is_string($source), 'final submit browser contract source must be readable');
}

assertFinalSubmitBrowserContract(str_contains($shell, 'data-jzopc-finalization-url'), 'finalization URL must be trusted server bootstrap data');
assertFinalSubmitBrowserContract(str_contains($shell, 'data-jzopc-final-submit'), 'checkout must expose one explicit final order action');
assertFinalSubmitBrowserContract(str_contains($shell, 'aria-live="polite"'), 'final submit status must be announced accessibly');
assertFinalSubmitBrowserContract(str_contains($client, 'cryptoObject.getRandomValues(bytes)'), 'submission attempt ID must use browser cryptographic randomness');
assertFinalSubmitBrowserContract(str_contains($client, "body.set('submissionAttempt', attemptId)"), 'final preflight must bind the idempotency attempt');
assertFinalSubmitBrowserContract(str_contains($client, "body.set('finalizationAction', action)"), 'finalization requests must distinguish begin from recovery release');
assertFinalSubmitBrowserContract(str_contains($client, "body.set('finalizationAction', 'release')"), 'local handoff failure must have an explicit attempt-scoped release request');
assertFinalSubmitBrowserContract(str_contains($client, 'await this.bestEffortRelease(attemptId)'), 'network/local handoff failures must attempt to release their own reservation');
assertFinalSubmitBrowserContract(str_contains($client, "error.code === 'stale_state'"), 'final preflight must detect stale state');
assertFinalSubmitBrowserContract(str_contains($client, "return this.request(action, attemptId, true)"), 'stale retry must reuse the exact same idempotency attempt');
assertFinalSubmitBrowserContract(str_contains($client, "selected.classList.contains('binary')"), 'binary payment must be detected explicitly');
assertFinalSubmitBrowserContract(str_contains($client, "jzopc:checkout:binary-payment-required"), 'unsupported binary handoff must fail closed with a lifecycle signal');
assertFinalSubmitBrowserContract(str_contains($client, "window.jQuery(form).trigger('submit')"), 'PrestaShop/jQuery payment submit handlers must be preserved when available');
assertFinalSubmitBrowserContract(str_contains($client, 'form.requestSubmit()'), 'native submit-event semantics must be preserved without jQuery when supported');
assertFinalSubmitBrowserContract(str_contains($client, 'HTMLFormElement.prototype.submit.call(form)'), 'legacy browser fallback may use raw form submission only as a last resort');
assertFinalSubmitBrowserContract(!str_contains($client, 'validateOrder('), 'browser must never attempt PrestaShop order creation');
assertFinalSubmitBrowserContract(str_contains($client, "this.root.toggleAttribute('data-jzopc-finalizing', busy)"), 'final handoff needs an explicit in-progress state');
assertFinalSubmitBrowserContract(str_contains($client, 'control.disabled = true'), 'checkout controls must be frozen while the reserved handoff is in progress');
assertFinalSubmitBrowserContract(str_contains($client, 'paymentForm.contains(control)'), 'native payment form inputs must not be disabled before handoff');
assertFinalSubmitBrowserContract(str_contains($client, "data-jzopc-final-message"), 'user-facing client messages must be read from translated server markup');
assertFinalSubmitBrowserContract(str_contains($payment, 'js-payment-option-form'), 'payment section must preserve native-form containers used by handoff');
assertFinalSubmitBrowserContract(is_string($module) && str_contains($module, 'private const INTEGRATION_SHELL_READY = false;'), 'readiness gate must remain closed while binary/runtime gates are unfinished');

fwrite(STDOUT, "Checkout final submit browser contract smoke tests passed.\n");

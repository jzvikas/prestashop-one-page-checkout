<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$client = file_get_contents($root . '/views/js/final-submit-controller.js');
$binary = file_get_contents($root . '/views/js/binary-payment-controller.js');
$ambiguityGuard = file_get_contents($root . '/views/js/payment-handoff-ambiguity-guard.js');
$shell = file_get_contents($root . '/views/templates/front/checkout-shell.tpl');
$payment = file_get_contents($root . '/views/templates/front/sections/payment.tpl');
$assets = file_get_contents($root . '/src/Integration/CheckoutFrontendAssetRegistrar.php');
$module = file_get_contents($root . '/jzonepagecheckout.php');

function assertFinalSubmitBrowserContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

foreach ([$client, $binary, $ambiguityGuard, $shell, $payment, $assets, $module] as $source) {
    assertFinalSubmitBrowserContract(is_string($source) && $source !== '', 'final submit browser contract source must be readable');
}

assertFinalSubmitBrowserContract(str_contains($shell, 'data-jzopc-finalization-url'), 'finalization URL must be trusted server bootstrap data');
assertFinalSubmitBrowserContract(str_contains($shell, 'data-jzopc-final-submit'), 'checkout must expose one explicit non-binary final order action');
assertFinalSubmitBrowserContract(str_contains($shell, 'aria-live="polite"'), 'final submit status must be announced accessibly');
assertFinalSubmitBrowserContract(str_contains($shell, 'data-jzopc-final-message="handoff-ambiguous"'), 'ambiguous native handoff must have translated fail-closed messaging');
assertFinalSubmitBrowserContract(str_contains($client, 'cryptoObject.getRandomValues(bytes)'), 'submission attempt ID must use browser cryptographic randomness');
assertFinalSubmitBrowserContract(str_contains($client, "body.set('submissionAttempt', attemptId)"), 'final preflight must bind the idempotency attempt');
assertFinalSubmitBrowserContract(str_contains($client, "body.set('finalizationAction', action)"), 'finalization requests must distinguish begin from recovery release');
assertFinalSubmitBrowserContract(str_contains($client, "body.set('finalizationAction', 'release')"), 'pre-handoff local failures must have an explicit attempt-scoped release request');
assertFinalSubmitBrowserContract(str_contains($client, 'await this.bestEffortRelease(attemptId)'), 'pre-handoff failures must attempt to release their own reservation');
assertFinalSubmitBrowserContract(str_contains($client, "error.code === 'stale_state'"), 'final preflight must detect stale state');
assertFinalSubmitBrowserContract(str_contains($client, "return this.request(action, attemptId, true)"), 'stale retry must reuse the exact same idempotency attempt');
assertFinalSubmitBrowserContract(str_contains($client, "selected.classList.contains('binary')"), 'generic final action must detect binary payment explicitly');
assertFinalSubmitBrowserContract(str_contains($client, "jzopc:checkout:binary-payment-required"), 'generic final action must fail closed if binary interception is unavailable');
assertFinalSubmitBrowserContract(str_contains($client, "window.jQuery(form).trigger('submit')"), 'PrestaShop/jQuery payment submit handlers must be preserved when available');
assertFinalSubmitBrowserContract(str_contains($client, 'form.requestSubmit()'), 'native submit-event semantics must be preserved without jQuery when supported');
assertFinalSubmitBrowserContract(str_contains($client, 'HTMLFormElement.prototype.submit.call(form)'), 'legacy browser fallback may use raw form submission only as a last resort');
assertFinalSubmitBrowserContract(!str_contains($client, 'validateOrder('), 'browser must never attempt PrestaShop order creation');
assertFinalSubmitBrowserContract(str_contains($client, "this.root.toggleAttribute('data-jzopc-finalizing', busy)"), 'final handoff needs an explicit in-progress state');
assertFinalSubmitBrowserContract(str_contains($client, 'control.disabled = true'), 'checkout controls must be frozen while the reserved handoff is in progress');
assertFinalSubmitBrowserContract(str_contains($client, 'paymentForm.contains(control)'), 'native payment form inputs must not be disabled before handoff');
assertFinalSubmitBrowserContract(str_contains($client, "data-jzopc-final-message"), 'user-facing client messages must be read from translated server markup');
assertFinalSubmitBrowserContract(str_contains($client, "jzopc:checkout:payment-handoff-ambiguous"), 'ordinary native handler throw must publish the shared ambiguous-handoff lifecycle');

$ordinaryHandoffStart = strpos($client, 'async handoffToNativePayment(attemptId)');
$ordinaryHandoffEnd = strpos($client, 'findPaymentForm(optionId)', $ordinaryHandoffStart === false ? 0 : $ordinaryHandoffStart);
assertFinalSubmitBrowserContract($ordinaryHandoffStart !== false && $ordinaryHandoffEnd !== false, 'ordinary native handoff method must remain discoverable');
$ordinaryHandoff = substr($client, $ordinaryHandoffStart, $ordinaryHandoffEnd - $ordinaryHandoffStart);
assertFinalSubmitBrowserContract(
    !str_contains($ordinaryHandoff, "catch (error) {\n        await this.bestEffortRelease(attemptId)"),
    'ordinary native handler throw must not automatically release an already-started reservation',
);
assertFinalSubmitBrowserContract(
    str_contains($ordinaryHandoff, "this.dispatch('jzopc:checkout:payment-handoff-ambiguous'"),
    'ordinary post-activation exception must defer fail-closed locking to the shared ambiguity guard',
);

assertFinalSubmitBrowserContract(str_contains($binary, "this.root.addEventListener('click', this.onCaptureClick, true)"), 'binary activation must be intercepted in capture phase before module click handlers');
assertFinalSubmitBrowserContract(str_contains($binary, "this.root.addEventListener('submit', this.onCaptureSubmit, true)"), 'binary self-submitting forms must be intercepted in capture phase');
assertFinalSubmitBrowserContract(str_contains($binary, 'event.stopImmediatePropagation()'), 'binary module activation must not run before server preflight succeeds');
assertFinalSubmitBrowserContract(str_contains($binary, "body.set('finalizationAction', 'begin')"), 'binary payment must use the same server finalization preflight');
assertFinalSubmitBrowserContract(str_contains($binary, "body.set('finalizationAction', 'release')"), 'pre-handoff binary failure must release only its own reservation');
assertFinalSubmitBrowserContract(str_contains($binary, "return this.request(attemptId, true)"), 'binary stale retry must reuse the exact same attempt ID');
assertFinalSubmitBrowserContract(str_contains($binary, "Object.keys(payload.sections).length !== 0"), 'successful binary preflight must reject unexpected payment DOM replacement');
assertFinalSubmitBrowserContract(str_contains($binary, "activation.target.click()"), 'successful binary click handoff must replay the original trusted module control');
assertFinalSubmitBrowserContract(str_contains($binary, "window.jQuery(form).trigger('submit')"), 'binary form handoff must preserve jQuery submit lifecycle');
assertFinalSubmitBrowserContract(str_contains($binary, "form.requestSubmit()"), 'binary form handoff must preserve native submit lifecycle when available');
assertFinalSubmitBrowserContract(str_contains($binary, "'.js-payment-' + CSS.escape(moduleName)"), 'binary control discovery must follow Core js-payment-{module} container semantics');
assertFinalSubmitBrowserContract(str_contains($binary, "finalSubmit.hidden = selected instanceof HTMLInputElement"), 'generic final button must be hidden while a binary payment option owns final activation');
assertFinalSubmitBrowserContract(str_contains($binary, 'this.managedDisabledControls.set(control, control.disabled)'), 'agreement gating must preserve pre-existing payment-module disabled state');
assertFinalSubmitBrowserContract(str_contains($binary, 'let handoffStarted = false;'), 'binary controller must track whether module-owned activation has begun');
assertFinalSubmitBrowserContract(substr_count($binary, 'handoffStarted = true;') >= 2, 'binary click and submit replay must mark handoff started before invoking module code');
assertFinalSubmitBrowserContract(str_contains($binary, 'if (handoffStarted) {'), 'binary throw recovery must distinguish post-activation uncertainty from safe pre-handoff failure');
assertFinalSubmitBrowserContract(str_contains($binary, "jzopc:checkout:payment-handoff-ambiguous"), 'binary post-activation throw must publish the shared ambiguous-handoff lifecycle');
assertFinalSubmitBrowserContract(!str_contains($binary, 'validateOrder('), 'binary browser adapter must never create the PrestaShop order itself');
assertFinalSubmitBrowserContract(str_contains($assets, 'views/js/binary-payment-controller.js'), 'binary payment interception must be registered in the checkout asset set');
assertFinalSubmitBrowserContract(str_contains($assets, 'views/js/payment-handoff-ambiguity-guard.js'), 'shared ambiguity guard must be registered in the checkout asset set');

assertFinalSubmitBrowserContract(
    str_contains($ambiguityGuard, "document.addEventListener('jzopc:checkout:payment-handoff-ambiguous'")
        && str_contains($ambiguityGuard, 'scheduleAmbiguousLock(root);'),
    'shared guard must consume ambiguous native-handoff events and schedule fail-closed locking after controller cleanup',
);
assertFinalSubmitBrowserContract(
    str_contains($ambiguityGuard, 'Promise.resolve().then(function ()')
        && str_contains($ambiguityGuard, "root.setAttribute('data-jzopc-payment-handoff-ambiguous', 'true')")
        && str_contains($ambiguityGuard, "root.setAttribute('aria-busy', 'true')")
        && str_contains($ambiguityGuard, 'control.disabled = true'),
    'ambiguous handoff lock must run after controller cleanup and freeze the checkout locally',
);
assertFinalSubmitBrowserContract(
    str_contains($ambiguityGuard, "document.addEventListener('click', suppressLockedActivation, true)")
        && str_contains($ambiguityGuard, "document.addEventListener('submit', suppressLockedActivation, true)"),
    'locked checkout must suppress link/form payment activation before third-party handlers',
);

assertFinalSubmitBrowserContract(str_contains($payment, 'js-payment-option-form'), 'payment section must preserve native-form containers used by handoff');
assertFinalSubmitBrowserContract(str_contains($payment, 'data-module-name='), 'payment option must preserve Core module-name identity for binary container resolution');
assertFinalSubmitBrowserContract(is_string($module) && str_contains($module, 'private const INTEGRATION_SHELL_READY = false;'), 'readiness gate must remain closed while runtime gates are unfinished');

fwrite(STDOUT, "Checkout final submit browser contract smoke tests passed.\n");

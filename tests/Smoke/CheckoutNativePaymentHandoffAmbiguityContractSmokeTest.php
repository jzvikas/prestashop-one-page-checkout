<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$ordinary = file_get_contents($root . '/views/js/final-submit-controller.js');
$binary = file_get_contents($root . '/views/js/binary-payment-controller.js');
$module = file_get_contents($root . '/jzonepagecheckout.php');

function assertNativeHandoffAmbiguityContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

foreach ([$ordinary, $binary, $module] as $source) {
    assertNativeHandoffAmbiguityContract(is_string($source), 'native handoff ambiguity contract source must be readable');
}

$ordinaryMethodStart = strpos($ordinary, 'async handoffToNativePayment(attemptId)');
$ordinaryMethodEnd = strpos($ordinary, 'findPaymentForm(optionId)', $ordinaryMethodStart ?: 0);
assertNativeHandoffAmbiguityContract($ordinaryMethodStart !== false && $ordinaryMethodEnd !== false, 'ordinary native handoff method must be discoverable');
$ordinaryMethod = substr($ordinary, $ordinaryMethodStart, $ordinaryMethodEnd - $ordinaryMethodStart);

assertNativeHandoffAmbiguityContract(
    str_contains($ordinaryMethod, 'await this.bestEffortRelease(attemptId);'),
    'ordinary handoff must still release when the native payment form disappears before activation'
);
assertNativeHandoffAmbiguityContract(
    str_contains($ordinaryMethod, "window.jQuery(form).trigger('submit')")
        && str_contains($ordinaryMethod, 'form.requestSubmit()')
        && str_contains($ordinaryMethod, 'HTMLFormElement.prototype.submit.call(form)'),
    'ordinary handoff must preserve the native observable submit fallback chain'
);
assertNativeHandoffAmbiguityContract(
    str_contains($ordinaryMethod, "jzopc:checkout:payment-handoff-ambiguous"),
    'ordinary handoff exceptions after native activation begins must be classified as ambiguous'
);

$ordinaryCatchStart = strpos($ordinaryMethod, '} catch (error) {');
assertNativeHandoffAmbiguityContract($ordinaryCatchStart !== false, 'ordinary handoff catch block must be discoverable');
$ordinaryCatch = substr($ordinaryMethod, $ordinaryCatchStart);
assertNativeHandoffAmbiguityContract(
    !str_contains($ordinaryCatch, 'bestEffortRelease('),
    'ordinary native submit exceptions must not automatically release the duplicate-handoff reservation'
);

assertNativeHandoffAmbiguityContract(
    str_contains($binary, 'let nativeActivationStarted = false;'),
    'binary handoff must explicitly track whether module-owned activation has started'
);
assertNativeHandoffAmbiguityContract(
    str_contains($binary, "nativeActivationStarted = true;\n            activation.target.click();"),
    'binary click replay must mark native activation before invoking the module-owned control'
);
assertNativeHandoffAmbiguityContract(
    str_contains($binary, "nativeActivationStarted = true;\n            this.submitForm(activation.target);"),
    'binary form replay must mark native activation before invoking the module-owned submit lifecycle'
);
assertNativeHandoffAmbiguityContract(
    str_contains($binary, "if (!nativeActivationStarted && error && error.name === 'AbortError')"),
    'AbortError may be treated as a preflight abort only before native payment activation starts'
);
assertNativeHandoffAmbiguityContract(
    str_contains($binary, "if (!nativeActivationStarted) {\n          await this.bestEffortRelease(attemptId);\n        } else {"),
    'binary errors may release only when native module activation definitely has not started'
);
assertNativeHandoffAmbiguityContract(
    str_contains($binary, "jzopc:checkout:payment-handoff-ambiguous"),
    'binary exceptions after native activation begins must preserve the barrier and publish the ambiguity lifecycle event'
);
assertNativeHandoffAmbiguityContract(
    !str_contains($ordinary, 'validateOrder(') && !str_contains($binary, 'validateOrder('),
    'browser adapters must never create PrestaShop orders directly'
);
assertNativeHandoffAmbiguityContract(
    is_string($module) && str_contains($module, 'private const INTEGRATION_SHELL_READY = false;'),
    'production readiness gate must remain closed while browser/runtime evidence is pending'
);

fwrite(STDOUT, "Checkout native payment handoff ambiguity contract smoke tests passed.\n");

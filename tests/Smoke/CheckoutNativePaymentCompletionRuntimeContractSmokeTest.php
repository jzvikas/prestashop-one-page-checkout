<?php

declare(strict_types=1);

$browser = file_get_contents(__DIR__ . '/../Browser/native-payment-order-cleanup-browser-contract.mjs');
$runtime = file_get_contents(__DIR__ . '/../Runtime/CompletedOrderCleanupContract.php');
$workflow = file_get_contents(__DIR__ . '/../../.github/workflows/native-payment-runtime.yml');

if (!is_string($browser) || !is_string($runtime) || !is_string($workflow)) {
    fwrite(STDERR, "Native payment runtime contract sources are missing.\n");
    exit(1);
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$assert(str_contains($browser, '[data-jzopc-final-submit]'), 'browser contract must cross the real OPC final-submit button');
$assert(str_contains($browser, 'ps_checkpayment'), 'browser contract must use the official check-payment module fixture');
$assert(str_contains($browser, 'order-confirmation'), 'browser contract must require Core order confirmation');
$assert(str_contains($browser, 'isCheckPaymentValidation'), 'browser contract must distinguish the payment-module validation request from final preflight');
$assert(str_contains($browser, 'page.waitForRequest('), 'browser contract must prove that the payment-module validation request actually leaves Chromium');
$assert(str_contains($browser, 'page.waitForResponse('), 'browser contract must observe the payment-module validation response structurally');
$assert(str_contains($browser, 'paymentHandoffShape()'), 'browser contract must validate action-only form shape before native handoff');
$assert(str_contains($browser, 'form.evaluate((node, evaluatedOptionId) => {'), 'browser form-shape diagnostics must explicitly pass the selected option identity into the browser realm');
$assert(str_contains($browser, 'optionId: evaluatedOptionId,'), 'browser form-shape diagnostics must not depend on an unserialized Node-side closure');
$assert(str_contains($browser, '}, optionId);'), 'browser form-shape diagnostics must transport the selected option identity as the evaluate argument');
$assert(str_contains($browser, 'waitForSafeHandoffTrace'), 'browser contract must await lifecycle evidence outside the navigating page execution context');
$assert(str_contains($browser, 'new Promise((resolve) => setTimeout(resolve, 20))'), 'navigation-safe lifecycle wait must yield through the Node event loop');
$assert(!str_contains($browser, 'page.waitForFunction(() => true'), 'post-handoff synchronization must not depend on a page execution context destroyed by native navigation');
$assert(str_contains($browser, 'action_path='), 'handoff failure diagnostics must expose only the payment action path');
$assert(str_contains($browser, 'final_path='), 'handoff failure diagnostics must expose only the final navigation path');
$assert(str_contains($browser, 'validation_status='), 'post-validation failure diagnostics must preserve structural response status');
$assert(str_contains($browser, 'jzopc:checkout:payment-handoff'), 'browser contract must record the guarded handoff event boundary');
$assert(str_contains($browser, 'jzopc:checkout:payment-submit-blocked'), 'browser contract must distinguish an authorization rejection from a missing network request');
$assert(!str_contains($browser, 'postData('), 'browser diagnostics must never log payment request bodies');
$assert(!str_contains($browser, 'allHeaders('), 'browser diagnostics must never log request headers or cookies');
$assert(!str_contains($browser, 'request.headers('), 'browser diagnostics must never log request headers or cookies');
$assert(!str_contains($browser, 'response.text('), 'browser diagnostics must never dump payment response bodies');
$assert(!str_contains($browser, 'validateOrder('), 'browser contract must never create a Core order directly');
$assert(!str_contains($runtime, 'validateOrder('), 'cleanup probe must never create a Core order directly');
$assert(str_contains($runtime, "(string) \$order->module === 'ps_checkpayment'"), 'cleanup probe must prove payment-module ownership');
$assert(str_contains($runtime, "['jzopc_checkout_finalization', 'jzopc_checkout_selection']"), 'cleanup probe must verify both transient tables');
$assert(str_contains($runtime, '$orderCount === 1'), 'cleanup probe must prove exactly one Core order exists for the cart');
$assert(str_contains($workflow, 'node native-payment-order-cleanup-browser-contract.mjs'), 'runtime workflow must execute the native payment browser contract');
$assert(str_contains($workflow, 'CompletedOrderCleanupContract.php'), 'runtime workflow must execute post-order cleanup verification');
$assert(str_contains($workflow, 'git checkout 163eea350e29616f7cff343285d8c4bcc2b6cc44'), 'runtime payment fixture must remain pinned');

fwrite(STDOUT, "Native payment completion runtime source contract OK\n");

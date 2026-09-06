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
$assert(!str_contains($browser, 'validateOrder('), 'browser contract must never create a Core order directly');
$assert(!str_contains($runtime, 'validateOrder('), 'cleanup probe must never create a Core order directly');
$assert(str_contains($runtime, "(string) \$order->module === 'ps_checkpayment'"), 'cleanup probe must prove payment-module ownership');
$assert(str_contains($runtime, "['jzopc_checkout_finalization', 'jzopc_checkout_selection']"), 'cleanup probe must verify both transient tables');
$assert(str_contains($runtime, '$orderCount === 1'), 'cleanup probe must prove exactly one Core order exists for the cart');
$assert(str_contains($workflow, 'node native-payment-order-cleanup-browser-contract.mjs'), 'runtime workflow must execute the native payment browser contract');
$assert(str_contains($workflow, 'CompletedOrderCleanupContract.php'), 'runtime workflow must execute post-order cleanup verification');
$assert(str_contains($workflow, 'git checkout 163eea350e29616f7cff343285d8c4bcc2b6cc44'), 'runtime payment fixture must remain pinned');

fwrite(STDOUT, "Native payment completion runtime source contract OK\n");

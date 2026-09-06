<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/Browser/native-payment-order-cleanup-browser-contract.mjs');
if ($source === false) {
    fwrite(STDERR, "FAIL: unable to read native payment browser contract.\n");
    exit(1);
}

$required = [
    "await page.exposeFunction('jzopcRuntimeTraceEvent'",
    "void window.jzopcRuntimeTraceEvent('preflight');",
    "void window.jzopcRuntimeTraceEvent('handoff');",
    "const preflightResponse = await finalizationRequest;",
    "if (preflightResponse.status() >= 400)",
    "(request) => isCheckPaymentValidation(request.url())",
    "handoffTrace.preflight < 1",
    "handoffTrace.handoff < 1",
    "handoffTrace.blocked !== 0",
    "handoffTrace.ambiguous !== 0",
    "await page.waitForURL((url) => isOrderConfirmation(url.toString())",
    "JZOPC_NATIVE_ORDER_ID=",
];

foreach ($required as $needle) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "FAIL: native payment contract must preserve navigation-safe lifecycle/order gates: {$needle}\n");
        exit(1);
    }
}

$forbiddenBodyReads = [
    'await preflightResponse.json();',
    'await preflightResponse.body();',
    'await preflightResponse.text();',
];
foreach ($forbiddenBodyReads as $needle) {
    if (str_contains($source, $needle)) {
        fwrite(STDERR, "FAIL: native payment runtime must not race native navigation by reading the finalization body in Playwright: {$needle}\n");
        exit(1);
    }
}

$forbiddenOwnership = [
    'validateOrder(',
    'INSERT INTO ps_orders',
    'INSERT INTO `ps_orders`',
];
foreach ($forbiddenOwnership as $needle) {
    if (str_contains($source, $needle)) {
        fwrite(STDERR, "FAIL: native payment browser contract must not create orders directly: {$needle}\n");
        exit(1);
    }
}

fwrite(STDOUT, "Navigation-safe native payment lifecycle trace smoke contract passed.\n");

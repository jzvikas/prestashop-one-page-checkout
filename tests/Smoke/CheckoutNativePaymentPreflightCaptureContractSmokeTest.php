<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/Browser/native-payment-order-cleanup-browser-contract.mjs');
if ($source === false) {
    fwrite(STDERR, "FAIL: unable to read native payment browser contract.\n");
    exit(1);
}

$required = [
    "page.waitForResponse((response) => {",
    "}).then(async (response) => {",
    "payload = await response.json();",
    "return { status, payload };",
    "const preflight = await finalizationRequest;",
    "const preflightPayload = preflight.payload;",
];

foreach ($required as $needle) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "FAIL: native payment contract must capture finalization JSON before native navigation: {$needle}\n");
        exit(1);
    }
}

if (str_contains($source, 'const preflightResponse = await finalizationRequest;')
    || str_contains($source, 'await preflightResponse.json();')) {
    fwrite(STDERR, "FAIL: native payment contract must not defer finalization body reads until after handoff navigation can begin.\n");
    exit(1);
}

if (!str_contains($source, "(request) => isCheckPaymentValidation(request.url())")
    || !str_contains($source, "await page.waitForURL((url) => isOrderConfirmation(url.toString())")
    || !str_contains($source, 'JZOPC_NATIVE_ORDER_ID=')) {
    fwrite(STDERR, "FAIL: preflight capture hardening must preserve payment validation and Core order-confirmation gates.\n");
    exit(1);
}

$forbidden = [
    'validateOrder(',
    'INSERT INTO ps_orders',
    'INSERT INTO `ps_orders`',
];
foreach ($forbidden as $needle) {
    if (str_contains($source, $needle)) {
        fwrite(STDERR, "FAIL: native payment browser contract must not create orders directly: {$needle}\n");
        exit(1);
    }
}

fwrite(STDOUT, "Native payment preflight capture smoke contract passed.\n");

<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$browserPath = $root . '/tests/Browser/native-payment-ambiguous-handoff-browser-contract.mjs';
$runtimePath = $root . '/tests/Runtime/AmbiguousPaymentReservationContract.php';
$workflowPath = $root . '/.github/workflows/native-payment-runtime.yml';

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};
$read = static function (string $path) use ($fail): string {
    $content = is_file($path) ? file_get_contents($path) : false;
    if (!is_string($content)) {
        $fail(sprintf('Unable to read %s.', $path));
    }

    return $content;
};
$contains = static function (string $haystack, string $needle, string $message) use ($fail): void {
    if (!str_contains($haystack, $needle)) {
        $fail($message);
    }
};

$browser = $read($browserPath);
$runtime = $read($runtimePath);
$workflow = $read($workflowPath);

foreach ([
    "jzopc:checkout:payment-handoff-ambiguous",
    "jzopc:checkout:payment-handoff-locked",
    "data-jzopc-payment-handoff-ambiguous",
    "JZOPC_AMBIGUOUS_CART_ID=",
    "validation request escaped after injected synchronous handoff failure",
    "locked checkout allowed a native payment retry",
    "locked checkout allowed a second finalization request",
] as $needle) {
    $contains($browser, $needle, sprintf('Ambiguous browser contract lost required assertion: %s', $needle));
}

foreach ([
    "SELECT COUNT(*) FROM `%sorders` WHERE id_cart = ?",
    "jzopc_checkout_finalization",
    "expires_at > UNIX_TIMESTAMP() AS is_active",
    "jzopc_checkout_selection",
    "order_count=0, active_reservation=1",
] as $needle) {
    $contains($runtime, $needle, sprintf('Ambiguous reservation runtime contract lost required assertion: %s', $needle));
}

foreach ([
    'Execute ambiguous native payment handoff Chromium contract',
    'Verify ambiguous handoff preserved reservation',
    'native-payment-ambiguous-handoff-browser-contract.mjs',
    'AmbiguousPaymentReservationContract.php',
] as $needle) {
    $contains($workflow, $needle, sprintf('Native payment workflow lost ambiguous handoff gate: %s', $needle));
}

if (str_contains($browser, 'validateOrder(') || str_contains($runtime, 'validateOrder(')) {
    $fail('Ambiguous handoff runtime gate must not create orders directly.');
}

fwrite(STDOUT, "Ambiguous payment runtime source contract smoke test OK\n");

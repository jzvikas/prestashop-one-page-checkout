<?php

declare(strict_types=1);

$path = __DIR__ . '/../Runtime/ActiveCoreFreeOrderFailureDiagnostic.php';
$source = file_get_contents($path);

if (!is_string($source) || $source === '') {
    fwrite(STDERR, "Missing free-order failure diagnostic source.\n");
    exit(1);
}

$required = [
    "PHP_SAPI !== 'cli'",
    "JZOPC_RUNTIME_ACTIVE_FIXTURE",
    "str_starts_with(\$root, '/tmp/prestashop')",
    "JZOPC_RUNTIME_FREE_PRODUCT_ID",
    "Order::getIdByCartId(\$cartId)",
    "jzopc_checkout_finalization",
    "jzopc_checkout_selection",
    "JZOPC_FREE_ORDER_DIAGNOSTIC_RESERVATION_COUNT",
    "JZOPC_FREE_ORDER_DIAGNOSTIC_SELECTION_FREE_ORDER",
];

foreach ($required as $needle) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "Free-order diagnostic is missing required contract: {$needle}\n");
        exit(1);
    }
}

$forbidden = [
    'validateOrder(',
    'PaymentFree',
    'INSERT INTO',
    'UPDATE `' . "' . bqSQL",
    'DELETE FROM',
    'cookie',
    'csrf',
    'secure_key',
    'email',
    'firstname',
    'lastname',
];

foreach ($forbidden as $needle) {
    if (stripos($source, $needle) !== false) {
        fwrite(STDERR, "Free-order diagnostic crossed read-only/sensitive boundary: {$needle}\n");
        exit(1);
    }
}

echo "Checkout free-order failure diagnostic contract smoke test OK.\n";

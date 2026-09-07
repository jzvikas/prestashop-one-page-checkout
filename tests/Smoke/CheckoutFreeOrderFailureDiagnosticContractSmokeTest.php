<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$path = $root . '/tests/Runtime/ActiveCoreFreeOrderFailureDiagnostic.php';
$source = file_get_contents($path);
$workflow = file_get_contents($root . '/.github/workflows/native-payment-runtime.yml');

if (!is_string($source) || $source === '' || !is_string($workflow) || $workflow === '') {
    fwrite(STDERR, "Missing free-order failure diagnostic source or runtime workflow.\n");
    exit(1);
}

$required = [
    "PHP_SAPI !== 'cli'",
    "JZOPC_RUNTIME_ACTIVE_FIXTURE",
    "str_starts_with(\$root, '/tmp/prestashop')",
    "JZOPC_RUNTIME_FREE_PRODUCT_ID",
    "Context::getContext()",
    "\$context->cart = \$cart",
    "Order::getIdByCartId(\$cartId)",
    "jzopc_checkout_finalization",
    "jzopc_checkout_selection",
    "selected_payment_option",
    "JZOPC_FREE_ORDER_DIAGNOSTIC_RESERVATION_COUNT",
    "JZOPC_FREE_ORDER_DIAGNOSTIC_SELECTION_FREE_ORDER",
];

foreach ($required as $needle) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "Free-order diagnostic is missing required contract: {$needle}\n");
        exit(1);
    }
}

$contextBinding = strpos($source, '$context->cart = $cart');
$totalRead = strpos($source, '$cart->getOrderTotal(true, Cart::BOTH)');
if ($contextBinding === false || $totalRead === false || $contextBinding >= $totalRead) {
    fwrite(STDERR, "Free-order diagnostic must bind the loaded Core cart into Context before calculating the Core total.\n");
    exit(1);
}

$forbiddenExplicitBounds = [
    "cp.`id_cart` DESC LIMIT 1",
    "jzopc_checkout_selection` WHERE `id_cart` = ' . (int) \$cartId . ' LIMIT 1",
];
foreach ($forbiddenExplicitBounds as $needle) {
    if (str_contains($source, $needle)) {
        fwrite(STDERR, "Free-order diagnostic must not append LIMIT 1 before Db::getValue()/Db::getRow(), which already bound the query: {$needle}\n");
        exit(1);
    }
}

$requiredWorkflow = [
    'Diagnose Core free-order state on failure',
    'if: failure()',
    'ActiveCoreFreeOrderFailureDiagnostic.php',
    'JZOPC_PRESTASHOP_ROOT: /tmp/prestashop',
    "JZOPC_RUNTIME_ACTIVE_FIXTURE: '1'",
    'JZOPC_RUNTIME_FREE_PRODUCT_ID:',
];
foreach ($requiredWorkflow as $needle) {
    if (!str_contains($workflow, $needle)) {
        fwrite(STDERR, "Free-order failure diagnostic is not locked into runtime failure handling: {$needle}\n");
        exit(1);
    }
}

$forbidden = [
    'payment_option_key',
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

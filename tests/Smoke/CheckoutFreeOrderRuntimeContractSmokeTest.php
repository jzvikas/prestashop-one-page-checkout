<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$workflow = file_get_contents($root . '/.github/workflows/native-payment-runtime.yml');
$fixture = file_get_contents($root . '/tests/Runtime/PrepareFreeOrderProduct.php');
$browser = file_get_contents($root . '/tests/Browser/free-order-core-completion-browser-contract.mjs');
$probe = file_get_contents($root . '/tests/Runtime/FreeOrderCompletionContract.php');
$mutation = file_get_contents($root . '/src/Checkout/Mutation/CheckoutFinalizationMutation.php');

foreach ([$workflow, $fixture, $browser, $probe, $mutation] as $source) {
    if (!is_string($source) || $source === '') {
        fwrite(STDERR, "Unable to read free-order runtime contract source.\n");
        exit(1);
    }
}

$requiredWorkflow = [
    'Prepare zero-total Core product fixture',
    'PrepareFreeOrderProduct.php /tmp/prestashop 9.1',
    'JZOPC_RUNTIME_FREE_PRODUCT_ID',
    'Execute Core free-order completion Chromium contract',
    'free-order-core-completion-browser-contract.mjs',
    'Verify Core free-order ownership and OPC cleanup',
    'FreeOrderCompletionContract.php',
];
foreach ($requiredWorkflow as $needle) {
    if (!str_contains($workflow, $needle)) {
        fwrite(STDERR, "Free-order workflow gate is missing: {$needle}\n");
        exit(1);
    }
}

$requiredFixture = [
    "getenv('JZOPC_RUNTIME_ACTIVE_FIXTURE') !== '1'",
    "\$shopRoot !== '/tmp/prestashop'",
    "str_starts_with(\$modulePath, '/tmp/jzopc-active-fixture')",
    '$product->price = 0.0;',
    'StockAvailable::setQuantity',
];
foreach ($requiredFixture as $needle) {
    if (!str_contains($fixture, $needle)) {
        fwrite(STDERR, "Free-order fixture safety contract is missing: {$needle}\n");
        exit(1);
    }
}

$requiredBrowser = [
    "data-module-name') !== 'free_order'",
    "formShape.freeOrder !== '1'",
    "formShape.cartId === '' || formShape.cartId === initial.cartId",
    '!actionIsOrderConfirmation',
    "formShape.method !== 'POST'",
    'trace.preflight < 1',
    'trace.handoff < 1',
    'trace.blocked !== 0',
    'trace.ambiguous !== 0',
    "moduleId !== '-1'",
    "await page.reload({ waitUntil: 'domcontentloaded'",
    'JZOPC_FREE_ORDER_CART_ID=',
    'JZOPC_FREE_ORDER_ID=',
];
foreach ($requiredBrowser as $needle) {
    if (!str_contains($browser, $needle)) {
        fwrite(STDERR, "Free-order Chromium contract is missing: {$needle}\n");
        exit(1);
    }
}

$forbiddenBrowserContracts = [
    "formShape.cartId !== initial.cartId || formShape.method",
    'cart_bound=',
];
foreach ($forbiddenBrowserContracts as $forbidden) {
    if (str_contains($browser, $forbidden)) {
        fwrite(STDERR, "Free-order Chromium contract must not require a non-Core id_cart action query: {$forbidden}\n");
        exit(1);
    }
}

$requiredProbe = [
    "(string) \$order->module === 'free_order'",
    'total_paid_tax_incl',
    'total_paid_tax_excl',
    'SELECT COUNT(*) FROM `%sorders` WHERE id_cart = ?',
    'jzopc_checkout_finalization',
    'jzopc_checkout_selection',
    'Order::getIdByCartId($cartId) === $orderId',
];
foreach ($requiredProbe as $needle) {
    if (!str_contains($probe, $needle)) {
        fwrite(STDERR, "Free-order Core completion probe is missing: {$needle}\n");
        exit(1);
    }
}

foreach ([$fixture, $browser, $probe, $mutation] as $source) {
    foreach (['validateOrder(', 'new PaymentFree', 'new \\PaymentFree', 'INSERT INTO `ps_orders`'] as $forbidden) {
        if (str_contains($source, $forbidden)) {
            fwrite(STDERR, "Free-order runtime must not create orders directly: {$forbidden}\n");
            exit(1);
        }
    }
}

fwrite(STDOUT, "Free-order Core completion runtime source contract passed.\n");
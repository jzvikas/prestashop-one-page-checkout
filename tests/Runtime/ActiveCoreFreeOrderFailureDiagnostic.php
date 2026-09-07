<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This diagnostic is CLI-only.\n");
    exit(2);
}

$root = realpath((string) getenv('JZOPC_PRESTASHOP_ROOT'));
$productId = filter_var(getenv('JZOPC_RUNTIME_FREE_PRODUCT_ID'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if ($root === false || !str_starts_with($root, '/tmp/prestashop')) {
    fwrite(STDERR, "JZOPC_PRESTASHOP_ROOT must resolve below /tmp/prestashop.\n");
    exit(2);
}
if (getenv('JZOPC_RUNTIME_ACTIVE_FIXTURE') !== '1') {
    fwrite(STDERR, "JZOPC_RUNTIME_ACTIVE_FIXTURE=1 is required.\n");
    exit(2);
}
if ($productId === false) {
    fwrite(STDERR, "JZOPC_RUNTIME_FREE_PRODUCT_ID must be a positive integer.\n");
    exit(2);
}

require_once $root . '/config/config.inc.php';

$db = Db::getInstance();
$prefix = _DB_PREFIX_;

$cartId = (int) $db->getValue(
    'SELECT cp.`id_cart`'
    . ' FROM `' . bqSQL($prefix) . 'cart_product` cp'
    . ' INNER JOIN `' . bqSQL($prefix) . 'cart` c ON c.`id_cart` = cp.`id_cart`'
    . ' WHERE cp.`id_product` = ' . (int) $productId
    . ' ORDER BY c.`date_upd` DESC, cp.`id_cart` DESC'
);

if ($cartId <= 0) {
    echo "JZOPC_FREE_ORDER_DIAGNOSTIC=cart_not_found\n";
    exit(0);
}

$cart = new Cart($cartId);
if (!Validate::isLoadedObject($cart)) {
    fwrite(STDERR, "Latest free-order cart could not be loaded.\n");
    exit(2);
}

$orderCount = (int) $db->getValue(
    'SELECT COUNT(*) FROM `' . bqSQL($prefix) . 'orders` WHERE `id_cart` = ' . (int) $cartId
);
$orderId = (int) Order::getIdByCartId($cartId);
$reservationCount = (int) $db->getValue(
    'SELECT COUNT(*) FROM `' . bqSQL($prefix) . 'jzopc_checkout_finalization` WHERE `id_cart` = ' . (int) $cartId
);
$selection = $db->getRow(
    'SELECT `selected_payment_option` FROM `' . bqSQL($prefix) . 'jzopc_checkout_selection` WHERE `id_cart` = ' . (int) $cartId
);
$selectionCount = is_array($selection) ? 1 : 0;
$paymentState = is_array($selection) ? (string) ($selection['selected_payment_option'] ?? '') : '';

/*
 * Emit the order/reservation evidence before optional Core pricing. A failure in
 * CLI context hydration must never hide whether Core already persisted an order
 * or whether the OPC handoff reservation is still protecting this cart.
 */
printf("JZOPC_FREE_ORDER_DIAGNOSTIC_CART_ID=%d\n", $cartId);
printf("JZOPC_FREE_ORDER_DIAGNOSTIC_CUSTOMER_BOUND=%d\n", (int) $cart->id_customer > 0 ? 1 : 0);
printf("JZOPC_FREE_ORDER_DIAGNOSTIC_DELIVERY_ADDRESS=%d\n", (int) $cart->id_address_delivery > 0 ? 1 : 0);
printf("JZOPC_FREE_ORDER_DIAGNOSTIC_INVOICE_ADDRESS=%d\n", (int) $cart->id_address_invoice > 0 ? 1 : 0);
printf("JZOPC_FREE_ORDER_DIAGNOSTIC_ORDER_COUNT=%d\n", $orderCount);
printf("JZOPC_FREE_ORDER_DIAGNOSTIC_ORDER_ID_PRESENT=%d\n", $orderId > 0 ? 1 : 0);
printf("JZOPC_FREE_ORDER_DIAGNOSTIC_RESERVATION_COUNT=%d\n", $reservationCount);
printf("JZOPC_FREE_ORDER_DIAGNOSTIC_SELECTION_COUNT=%d\n", $selectionCount);
printf("JZOPC_FREE_ORDER_DIAGNOSTIC_SELECTION_FREE_ORDER=%d\n", str_starts_with($paymentState, 'free_order:') ? 1 : 0);

/*
 * If the browser timed out after Core created the order, summarize other database
 * sessions without emitting SQL text, connection identifiers, users, hosts or
 * arbitrary server state strings. This distinguishes an OPC transient-state
 * write waiting on a database lock from a stall before cleanup is attempted.
 */
try {
    $escapedPrefix = bqSQL($prefix);
    $processSummary = $db->getRow(
        'SELECT'
        . ' SUM(CASE WHEN `COMMAND` <> \'Sleep\' THEN 1 ELSE 0 END) AS `active_count`,'
        . ' SUM(CASE WHEN LOWER(COALESCE(`STATE`, \'\')) LIKE \'%lock%\' THEN 1 ELSE 0 END) AS `lock_wait_count`,'
        . ' SUM(CASE WHEN LOWER(COALESCE(`INFO`, \'\')) LIKE CONCAT(\'delete\', \' from `' . $escapedPrefix . 'jzopc\\_checkout\\_finalization`%\') THEN 1 ELSE 0 END) AS `finalization_delete_count`,'
        . ' SUM(CASE WHEN LOWER(COALESCE(`INFO`, \'\')) LIKE CONCAT(\'delete\', \' from `' . $escapedPrefix . 'jzopc\\_checkout\\_selection`%\') THEN 1 ELSE 0 END) AS `selection_delete_count`'
        . ' FROM `information_schema`.`PROCESSLIST`'
        . ' WHERE `DB` = DATABASE() AND `ID` <> CONNECTION_ID()'
    );

    if (is_array($processSummary)) {
        printf("JZOPC_FREE_ORDER_DIAGNOSTIC_DB_ACTIVE=%d\n", max(0, (int) ($processSummary['active_count'] ?? 0)));
        printf("JZOPC_FREE_ORDER_DIAGNOSTIC_DB_LOCK_WAIT=%d\n", max(0, (int) ($processSummary['lock_wait_count'] ?? 0)));
        printf("JZOPC_FREE_ORDER_DIAGNOSTIC_FINALIZATION_DELETE_ACTIVE=%d\n", max(0, (int) ($processSummary['finalization_delete_count'] ?? 0)));
        printf("JZOPC_FREE_ORDER_DIAGNOSTIC_SELECTION_DELETE_ACTIVE=%d\n", max(0, (int) ($processSummary['selection_delete_count'] ?? 0)));
    } else {
        echo "JZOPC_FREE_ORDER_DIAGNOSTIC_DB_PROCESS_AVAILABLE=0\n";
    }
} catch (Throwable) {
    echo "JZOPC_FREE_ORDER_DIAGNOSTIC_DB_PROCESS_AVAILABLE=0\n";
}

/*
 * Cart::getOrderTotal() reaches Core pricing helpers that expect both the active
 * cart and currency in Context. Hydrate only those already server-owned objects
 * for this read-only diagnostic. Pricing remains supplemental evidence.
 */
$context = Context::getContext();
$context->cart = $cart;
$currency = new Currency((int) $cart->id_currency);
if (!Validate::isLoadedObject($currency)) {
    echo "JZOPC_FREE_ORDER_DIAGNOSTIC_TOTAL_AVAILABLE=0\n";
    exit(0);
}
$context->currency = $currency;

try {
    $total = (float) $cart->getOrderTotal(true, Cart::BOTH);
    echo "JZOPC_FREE_ORDER_DIAGNOSTIC_TOTAL_AVAILABLE=1\n";
    printf("JZOPC_FREE_ORDER_DIAGNOSTIC_TOTAL_ZERO=%d\n", abs($total) < 0.000001 ? 1 : 0);
} catch (Throwable) {
    echo "JZOPC_FREE_ORDER_DIAGNOSTIC_TOTAL_AVAILABLE=0\n";
}

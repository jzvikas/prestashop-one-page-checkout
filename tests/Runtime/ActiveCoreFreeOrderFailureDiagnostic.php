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
    'SELECT `payment_option_key` FROM `' . bqSQL($prefix) . 'jzopc_checkout_selection` WHERE `id_cart` = ' . (int) $cartId
);
$selectionCount = is_array($selection) ? 1 : 0;
$paymentState = is_array($selection) ? (string) ($selection['payment_option_key'] ?? '') : '';
$total = (float) $cart->getOrderTotal(true, Cart::BOTH);

printf("JZOPC_FREE_ORDER_DIAGNOSTIC_CART_ID=%d\n", $cartId);
printf("JZOPC_FREE_ORDER_DIAGNOSTIC_TOTAL_ZERO=%d\n", abs($total) < 0.000001 ? 1 : 0);
printf("JZOPC_FREE_ORDER_DIAGNOSTIC_CUSTOMER_BOUND=%d\n", (int) $cart->id_customer > 0 ? 1 : 0);
printf("JZOPC_FREE_ORDER_DIAGNOSTIC_DELIVERY_ADDRESS=%d\n", (int) $cart->id_address_delivery > 0 ? 1 : 0);
printf("JZOPC_FREE_ORDER_DIAGNOSTIC_INVOICE_ADDRESS=%d\n", (int) $cart->id_address_invoice > 0 ? 1 : 0);
printf("JZOPC_FREE_ORDER_DIAGNOSTIC_ORDER_COUNT=%d\n", $orderCount);
printf("JZOPC_FREE_ORDER_DIAGNOSTIC_ORDER_ID_PRESENT=%d\n", $orderId > 0 ? 1 : 0);
printf("JZOPC_FREE_ORDER_DIAGNOSTIC_RESERVATION_COUNT=%d\n", $reservationCount);
printf("JZOPC_FREE_ORDER_DIAGNOSTIC_SELECTION_COUNT=%d\n", $selectionCount);
printf("JZOPC_FREE_ORDER_DIAGNOSTIC_SELECTION_FREE_ORDER=%d\n", str_starts_with($paymentState, 'free_order:') ? 1 : 0);

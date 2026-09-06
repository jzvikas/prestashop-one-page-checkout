<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

$shopRoot = $argv[1] ?? '';
$expectedFamily = $argv[2] ?? '';
$cartId = isset($argv[3]) ? (int) $argv[3] : 0;
$orderId = isset($argv[4]) ? (int) $argv[4] : 0;

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};
$assert = static function (bool $condition, string $message) use ($fail): void {
    if (!$condition) {
        $fail($message);
    }
};

if ($shopRoot === '' || !is_file($shopRoot . '/config/config.inc.php')) {
    $fail('Installed PrestaShop root is missing or invalid.');
}
if ($expectedFamily !== '9.1') {
    $fail('Completed-order cleanup contract currently targets PrestaShop 9.1 only.');
}
if ($cartId <= 0 || $orderId <= 0) {
    $fail('Completed-order cleanup contract requires positive cart/order IDs from the browser handoff.');
}

require_once $shopRoot . '/config/config.inc.php';
require_once $shopRoot . '/modules/jzonepagecheckout/vendor/autoload.php';

if (!defined('_PS_VERSION_') || !str_starts_with((string) _PS_VERSION_, '9.1.')) {
    $fail(sprintf('Expected PrestaShop 9.1 runtime, got %s.', defined('_PS_VERSION_') ? (string) _PS_VERSION_ : 'unknown'));
}

$order = new Order($orderId);
$assert(Validate::isLoadedObject($order), 'Native payment handoff did not create a loadable Core order.');
$assert((int) $order->id_cart === $cartId, 'Core order does not belong to the browser-reported cart.');
$assert((string) $order->module === 'ps_checkpayment', 'Core order was not created by ps_checkpayment.');

$dbHost = defined('_DB_SERVER_') ? trim((string) constant('_DB_SERVER_')) : '';
$dbPort = defined('_DB_PORT_') ? (int) constant('_DB_PORT_') : 0;
if ($dbHost !== '' && $dbPort <= 0 && preg_match('/\A([^:]+):(\d+)\z/D', $dbHost, $hostParts) === 1) {
    $dbHost = $hostParts[1];
    $dbPort = (int) $hostParts[2];
}
if ($dbHost === '') {
    $fail('Installed PrestaShop database host is unavailable.');
}

$params = [
    'driver' => 'pdo_mysql',
    'host' => $dbHost,
    'dbname' => defined('_DB_NAME_') ? (string) constant('_DB_NAME_') : '',
    'user' => defined('_DB_USER_') ? (string) constant('_DB_USER_') : '',
    'password' => defined('_DB_PASSWD_') ? (string) constant('_DB_PASSWD_') : '',
    'charset' => 'utf8mb4',
];
if ($dbPort > 0) {
    $params['port'] = $dbPort;
}
$connection = DriverManager::getConnection($params);
$assert($connection instanceof Connection, 'Unable to create installed PrestaShop DBAL connection.');

$prefix = defined('_DB_PREFIX_') ? (string) constant('_DB_PREFIX_') : '';
if (preg_match('/\A[A-Za-z0-9_]*\z/D', $prefix) !== 1) {
    $fail('Runtime database prefix is invalid.');
}
$shopId = (int) $order->id_shop;
$assert($shopId > 0, 'Core order has no valid shop identity.');

$orderCount = (int) $connection->fetchOne(
    sprintf('SELECT COUNT(*) FROM `%sorders` WHERE id_cart = ?', $prefix),
    [$cartId],
);
$assert($orderCount === 1, sprintf('Expected exactly one Core order for cart %d, found %d.', $cartId, $orderCount));

foreach (['jzopc_checkout_finalization', 'jzopc_checkout_selection'] as $suffix) {
    $remaining = (int) $connection->fetchOne(
        sprintf('SELECT COUNT(*) FROM `%s%s` WHERE id_shop = ? AND id_cart = ?', $prefix, $suffix),
        [$shopId, $cartId],
    );
    $assert($remaining === 0, sprintf('Post-order cleanup left %d row(s) in %s for cart %d.', $remaining, $suffix, $cartId));
}

$assert((int) Order::getIdByCartId($cartId) === $orderId, 'Core cart-to-order lookup disagrees with browser confirmation.');

fwrite(STDOUT, sprintf(
    "Native payment Core-order cleanup contract OK: cart=%d, order=%d, module=ps_checkpayment, transient_rows=0\n",
    $cartId,
    $orderId,
));

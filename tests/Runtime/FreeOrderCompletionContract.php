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

if (getenv('JZOPC_RUNTIME_ACTIVE_FIXTURE') !== '1') {
    $fail('Free-order completion probe requires the explicit active-fixture environment guard.');
}
if ($shopRoot !== '/tmp/prestashop' || !is_file($shopRoot . '/config/config.inc.php')) {
    $fail('Free-order completion probe only runs against /tmp/prestashop.');
}
if ($expectedFamily !== '9.1' || $cartId <= 0 || $orderId <= 0) {
    $fail('Free-order completion probe requires PrestaShop 9.1 and positive cart/order IDs.');
}

require_once $shopRoot . '/config/config.inc.php';
require_once $shopRoot . '/modules/jzonepagecheckout/vendor/autoload.php';

if (!defined('_PS_VERSION_') || !str_starts_with((string) _PS_VERSION_, '9.1.')) {
    $fail(sprintf('Expected PrestaShop 9.1 runtime, got %s.', defined('_PS_VERSION_') ? (string) _PS_VERSION_ : 'unknown'));
}
$modulePath = realpath($shopRoot . '/modules/jzonepagecheckout/jzonepagecheckout.php');
if (!is_string($modulePath) || !str_starts_with($modulePath, '/tmp/jzopc-active-fixture')) {
    $fail('Free-order completion probe refuses the production/source module tree.');
}

$order = new Order($orderId);
$assert(Validate::isLoadedObject($order), 'Core free-order handoff did not create a loadable order.');
$assert((int) $order->id_cart === $cartId, 'Core free order does not belong to the browser-reported cart.');
$assert((string) $order->module === 'free_order', 'Core free order was not created through PaymentFree ownership.');
$assert(abs((float) $order->total_paid_tax_incl) < 0.000001, 'Core free order has a non-zero tax-included paid total.');
$assert(abs((float) $order->total_paid_tax_excl) < 0.000001, 'Core free order has a non-zero tax-excluded paid total.');

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
$assert($shopId > 0, 'Core free order has no positive shop identity.');

$orderCount = (int) $connection->fetchOne(
    sprintf('SELECT COUNT(*) FROM `%sorders` WHERE id_cart = ?', $prefix),
    [$cartId],
);
$assert($orderCount === 1, sprintf('Expected exactly one Core free order for cart %d, found %d.', $cartId, $orderCount));
$assert((int) Order::getIdByCartId($cartId) === $orderId, 'Core cart-to-order lookup disagrees with free-order confirmation.');

foreach (['jzopc_checkout_finalization', 'jzopc_checkout_selection'] as $suffix) {
    $remaining = (int) $connection->fetchOne(
        sprintf('SELECT COUNT(*) FROM `%s%s` WHERE id_shop = ? AND id_cart = ?', $prefix, $suffix),
        [$shopId, $cartId],
    );
    $assert($remaining === 0, sprintf('Free-order cleanup left %d row(s) in %s for cart %d.', $remaining, $suffix, $cartId));
}

fwrite(STDOUT, sprintf(
    "Core free-order cleanup contract OK: cart=%d, order=%d, module=free_order, transient_rows=0\n",
    $cartId,
    $orderId,
));

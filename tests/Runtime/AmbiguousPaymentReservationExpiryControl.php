<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

$shopRootInput = rtrim((string) ($argv[1] ?? ''), DIRECTORY_SEPARATOR);
$expectedFamily = (string) ($argv[2] ?? '');
$cartId = isset($argv[3]) ? (int) $argv[3] : 0;

$fail = static function (string $message, int $code = 2): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit($code);
};
$assert = static function (bool $condition, string $message) use ($fail): void {
    if (!$condition) {
        $fail($message, 3);
    }
};

if (getenv('JZOPC_RUNTIME_ACTIVE_FIXTURE') !== '1') {
    $fail('Refusing ambiguity expiry control without JZOPC_RUNTIME_ACTIVE_FIXTURE=1.');
}
$shopRoot = $shopRootInput !== '' ? realpath($shopRootInput) : false;
if (!is_string($shopRoot) || $shopRoot !== '/tmp/prestashop') {
    $fail('Ambiguity expiry control is restricted to /tmp/prestashop.');
}
if ($expectedFamily !== '9.1' || $cartId <= 0 || !is_file($shopRoot . '/config/config.inc.php')) {
    $fail('Ambiguity expiry control requires PrestaShop 9.1 and a positive browser cart ID.');
}

require_once $shopRoot . '/config/config.inc.php';
require_once $shopRoot . '/modules/jzonepagecheckout/vendor/autoload.php';

$modulePath = realpath($shopRoot . '/modules/jzonepagecheckout/jzonepagecheckout.php');
if (!is_string($modulePath)
    || ($modulePath !== '/tmp/jzopc-active-fixture/jzonepagecheckout.php'
        && !str_starts_with($modulePath, '/tmp/jzopc-active-fixture-'))) {
    $fail('Ambiguity expiry control refuses the production/source module tree.');
}
if (!defined('_PS_VERSION_') || !str_starts_with((string) _PS_VERSION_, '9.1.')) {
    $fail('Ambiguity expiry control requires installed PrestaShop 9.1.');
}

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

$cart = new Cart($cartId);
$assert(Validate::isLoadedObject($cart), 'Browser-reported ambiguous cart is not loadable.');
$shopId = (int) $cart->id_shop;
$assert($shopId > 0 && (int) $cart->id_customer > 0, 'Ambiguous cart is missing shop/customer binding.');

$orderCount = (int) $connection->fetchOne(
    sprintf('SELECT COUNT(*) FROM `%sorders` WHERE id_cart = ?', $prefix),
    [$cartId],
);
$assert($orderCount === 0, 'Refusing to expire a reservation after a Core order exists.');

$table = $prefix . 'jzopc_checkout_finalization';
$updated = $connection->executeStatement(
    sprintf(
        'UPDATE `%s` SET expires_at = UNIX_TIMESTAMP() - 1 '
        . 'WHERE id_shop = ? AND id_cart = ? AND expires_at > UNIX_TIMESTAMP()',
        $table,
    ),
    [$shopId, $cartId],
);
$assert($updated === 1, 'Expected exactly one active ambiguity reservation to expire.');

$isActive = (int) $connection->fetchOne(
    sprintf(
        'SELECT COUNT(*) FROM `%s` WHERE id_shop = ? AND id_cart = ? AND expires_at > UNIX_TIMESTAMP()',
        $table,
    ),
    [$shopId, $cartId],
);
$assert($isActive === 0, 'Ambiguity reservation is still active according to MariaDB server time.');

fwrite(STDOUT, sprintf("Ambiguous reservation expiry control OK: cart=%d, active_reservation=0\n", $cartId));

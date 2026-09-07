<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

$shopRoot = $argv[1] ?? '';
$expectedFamily = $argv[2] ?? '';
$cartId = isset($argv[3]) ? (int) $argv[3] : 0;

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
    $fail('Ambiguous-payment reservation contract currently targets PrestaShop 9.1 only.');
}
if ($cartId <= 0) {
    $fail('Ambiguous-payment reservation contract requires a positive browser cart ID.');
}

require_once $shopRoot . '/config/config.inc.php';
require_once $shopRoot . '/modules/jzonepagecheckout/vendor/autoload.php';

if (!defined('_PS_VERSION_') || !str_starts_with((string) _PS_VERSION_, '9.1.')) {
    $fail(sprintf('Expected PrestaShop 9.1 runtime, got %s.', defined('_PS_VERSION_') ? (string) _PS_VERSION_ : 'unknown'));
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
$customerId = (int) $cart->id_customer;
$assert($shopId > 0 && $customerId > 0, 'Ambiguous cart is missing shop/customer binding.');

$orderCount = (int) $connection->fetchOne(
    sprintf('SELECT COUNT(*) FROM `%sorders` WHERE id_cart = ?', $prefix),
    [$cartId],
);
$assert($orderCount === 0, sprintf('Ambiguous handoff unexpectedly created %d Core order(s) for cart %d.', $orderCount, $cartId));

$row = $connection->fetchAssociative(
    sprintf(
        'SELECT id_customer, state_version, selected_payment_option, attempt_id, expires_at, '
        . 'expires_at > UNIX_TIMESTAMP() AS is_active FROM `%sjzopc_checkout_finalization` '
        . 'WHERE id_shop = ? AND id_cart = ?',
        $prefix,
    ),
    [$shopId, $cartId],
);
$assert(is_array($row), 'Ambiguous native handoff did not preserve a finalization reservation row.');
$assert((int) ($row['id_customer'] ?? 0) === $customerId, 'Preserved reservation lost its customer binding.');
$assert(is_string($row['state_version'] ?? null) && $row['state_version'] !== '', 'Preserved reservation has no state binding.');
$assert(is_string($row['selected_payment_option'] ?? null) && $row['selected_payment_option'] !== '', 'Preserved reservation has no payment binding.');
$assert(is_string($row['attempt_id'] ?? null) && $row['attempt_id'] !== '', 'Preserved reservation has no attempt binding.');
$assert((int) ($row['is_active'] ?? 0) === 1, 'Preserved reservation is already expired according to MariaDB server time.');

$selectionCount = (int) $connection->fetchOne(
    sprintf('SELECT COUNT(*) FROM `%sjzopc_checkout_selection` WHERE id_shop = ? AND id_cart = ?', $prefix),
    [$shopId, $cartId],
);
$assert($selectionCount === 1, sprintf('Ambiguous handoff should preserve one canonical selection row, found %d.', $selectionCount));

fwrite(STDOUT, sprintf(
    "Ambiguous native payment reservation contract OK: cart=%d, order_count=0, active_reservation=1, selection_rows=1\n",
    $cartId,
));

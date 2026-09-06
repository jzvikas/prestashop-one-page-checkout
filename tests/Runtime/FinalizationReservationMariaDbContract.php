<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Jzvikas\OnePageCheckout\Checkout\Finalization\CheckoutFinalizationReservationAlreadyActive;
use Jzvikas\OnePageCheckout\Infrastructure\Persistence\DbalCheckoutFinalizationReservationStore;

$shopRoot = $argv[1] ?? '';
$expectedFamily = $argv[2] ?? '';

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
    $fail('Finalization reservation MariaDB contract currently targets PrestaShop 9.1 only.');
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

$dbParams = [
    'driver' => 'pdo_mysql',
    'host' => $dbHost,
    'dbname' => defined('_DB_NAME_') ? (string) constant('_DB_NAME_') : '',
    'user' => defined('_DB_USER_') ? (string) constant('_DB_USER_') : '',
    'password' => defined('_DB_PASSWD_') ? (string) constant('_DB_PASSWD_') : '',
    'charset' => 'utf8mb4',
];
if ($dbPort > 0) {
    $dbParams['port'] = $dbPort;
}

$connection = DriverManager::getConnection($dbParams);
if (!$connection instanceof Connection) {
    $fail('Unable to create the installed PrestaShop Doctrine DBAL connection.');
}

$prefix = defined('_DB_PREFIX_') ? (string) constant('_DB_PREFIX_') : '';
if (preg_match('/\A[A-Za-z0-9_]*\z/D', $prefix) !== 1) {
    $fail('Runtime database prefix is invalid.');
}
$table = $prefix . 'jzopc_checkout_finalization';

$tableExists = (int) $connection->fetchOne(
    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
    [$table],
);
$assert($tableExists === 1, 'Installed finalization reservation table is missing.');

$context = Context::getContext();
$originalCart = $context->cart ?? null;
$shopId = (int) ($context->shop->id ?? 1);
if ($shopId <= 0) {
    $shopId = 1;
}

// Use a high synthetic cart identity only inside the module-owned reservation table. The contract
// deliberately does not create a Core order or mutate Core checkout business data.
$cartId = random_int(1_500_000_000, 2_000_000_000);
$customerA = random_int(1_000_000_000, 1_200_000_000);
$customerB = $customerA + 1;
$attemptA = bin2hex(random_bytes(16));
$attemptB = bin2hex(random_bytes(16));
$stateA = hash('sha256', 'state-a-' . $attemptA);
$stateB = hash('sha256', 'state-b-' . $attemptB);
$paymentA = 'runtime-payment-a';
$paymentB = 'runtime-payment-b';

$cart = new Cart();
$cart->id = $cartId;
$cart->id_shop = $shopId;
$cart->id_customer = $customerA;
$context->cart = $cart;

$store = new DbalCheckoutFinalizationReservationStore($connection, 900);

$cleanup = static function () use ($connection, $table, $shopId, $cartId): void {
    $connection->executeStatement(
        sprintf('DELETE FROM `%s` WHERE id_shop = ? AND id_cart = ?', $table),
        [$shopId, $cartId],
    );
};

try {
    $cleanup();

    $store->acquire($context, $stateA, $paymentA, $attemptA);
    $assert($store->isActive($context), 'First reservation acquisition did not create an active DB barrier.');

    // The exact same customer/state/payment/attempt is the only idempotent replay.
    $store->acquire($context, $stateA, $paymentA, $attemptA);
    $rowCount = (int) $connection->fetchOne(
        sprintf('SELECT COUNT(*) FROM `%s` WHERE id_shop = ? AND id_cart = ?', $table),
        [$shopId, $cartId],
    );
    $assert($rowCount === 1, 'Idempotent replay created more than one reservation row.');

    $competingBlocked = false;
    try {
        $store->acquire($context, $stateB, $paymentB, $attemptB);
    } catch (CheckoutFinalizationReservationAlreadyActive) {
        $competingBlocked = true;
    }
    $assert($competingBlocked, 'A competing attempt was not blocked by the active cart reservation.');

    // Simulate a stale/customer-transition tab still bound to the same Core cart ID. The active
    // cart-level handoff barrier must survive and must not become releasable by the new identity.
    $cart->id_customer = $customerB;
    $assert($store->isActive($context), 'Customer transition incorrectly removed the cart-level reservation barrier.');

    $crossCustomerBlocked = false;
    try {
        $store->acquire($context, $stateB, $paymentB, $attemptB);
    } catch (CheckoutFinalizationReservationAlreadyActive) {
        $crossCustomerBlocked = true;
    }
    $assert($crossCustomerBlocked, 'Cross-customer competing attempt was not blocked fail-closed.');

    $store->releaseAttempt($context, $attemptA);
    $assert($store->isActive($context), 'Mismatched customer released another customer reservation.');

    $cart->id_customer = $customerA;
    $store->releaseAttempt($context, $attemptB);
    $assert($store->isActive($context), 'Foreign attempt released the active reservation.');

    $store->releaseAttempt($context, $attemptA);
    $assert(!$store->isActive($context), 'Exact customer/attempt release did not clear a reservation with no Core order.');

    // Prove the installed MariaDB expiry path uses DB time and allows a new attempt only after the
    // previous row is actually expired. We force expiry in module-owned test data; no sleeps or
    // Core-order side effects are required.
    $store->acquire($context, $stateA, $paymentA, $attemptA);
    $connection->executeStatement(
        sprintf('UPDATE `%s` SET expires_at = UNIX_TIMESTAMP() - 1 WHERE id_shop = ? AND id_cart = ?', $table),
        [$shopId, $cartId],
    );
    $store->acquire($context, $stateB, $paymentB, $attemptB);

    $row = $connection->fetchAssociative(
        sprintf(
            'SELECT id_customer, state_version, selected_payment_option, attempt_id, expires_at > UNIX_TIMESTAMP() AS is_active '
            . 'FROM `%s` WHERE id_shop = ? AND id_cart = ?',
            $table,
        ),
        [$shopId, $cartId],
    );
    $assert(is_array($row), 'Replacement reservation row is missing after expiry recovery.');
    $assert((int) ($row['id_customer'] ?? 0) === $customerA, 'Expiry replacement changed reservation customer binding.');
    $assert(($row['state_version'] ?? null) === $stateB, 'Expiry replacement did not persist the new state version.');
    $assert(($row['selected_payment_option'] ?? null) === $paymentB, 'Expiry replacement did not persist the new payment selection.');
    $assert(($row['attempt_id'] ?? null) === $attemptB, 'Expiry replacement did not persist the new attempt.');
    $assert((int) ($row['is_active'] ?? 0) === 1, 'Expiry replacement is not active according to MariaDB server time.');

    fwrite(STDOUT, sprintf(
        "Finalization reservation MariaDB contract OK: PrestaShop %s, cart=%d, cart-barrier/idempotency/release/expiry verified\n",
        (string) _PS_VERSION_,
        $cartId,
    ));
} finally {
    try {
        $cleanup();
    } finally {
        $context->cart = $originalCart;
        $connection->close();
    }
}

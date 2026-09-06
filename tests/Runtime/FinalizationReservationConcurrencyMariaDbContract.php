<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Jzvikas\OnePageCheckout\Checkout\Finalization\CheckoutFinalizationReservationAlreadyActive;
use Jzvikas\OnePageCheckout\Infrastructure\Persistence\DbalCheckoutFinalizationReservationStore;

$shopRoot = $argv[1] ?? '';
$expectedFamily = $argv[2] ?? '';
$mode = $argv[3] ?? 'parent';

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
    $fail('Finalization reservation concurrency contract currently targets PrestaShop 9.1 only.');
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
    'dbname' => defined('_DB_NAME_') ? (string) constant('_DB_NAME_) : '',
    'user' => defined('_DB_USER_') ? (string) constant('_DB_USER_) : '',
    'password' => defined('_DB_PASSWD_') ? (string) constant('_DB_PASSWD_) : '',
    'charset' => 'utf8mb4',
];
if ($dbPort > 0) {
    $dbParams['port'] = $dbPort;
}

$prefix = defined('_DB_PREFIX_') ? (string) constant('_DB_PREFIX_) : '';
if (preg_match('/\A[A-Za-z0-9_]*\z/D', $prefix) !== 1) {
    $fail('Runtime database prefix is invalid.');
}
$table = $prefix . 'jzopc_checkout_finalization';

$connectionFactory = static function () use ($dbParams): Connection {
    return DriverManager::getConnection($dbParams);
};

if ($mode === 'worker') {
    $workerId = (int) ($argv[4] ?? -1);
    $cartId = (int) ($argv[5] ?? 0);
    $shopId = (int) ($argv[6] ?? 0);
    $customerId = (int) ($argv[7] ?? 0);
    $state = (string) ($argv[8] ?? '');
    $payment = (string) ($argv[9] ?? '');
    $attempt = (string) ($argv[10] ?? '');
    $gate = (string) ($argv[11] ?? '');

    if ($workerId < 0 || $cartId <= 0 || $shopId <= 0 || $customerId <= 0 || $state === '' || $payment === '' || $attempt === '' || $gate === '') {
        $fail('Worker arguments are invalid.');
    }

    $connection = $connectionFactory();
    $context = Context::getContext();
    $cart = new Cart();
    $cart->id = $cartId;
    $cart->id_shop = $shopId;
    $cart->id_customer = $customerId;
    $context->cart = $cart;
    $store = new DbalCheckoutFinalizationReservationStore($connection, 900);
    $ready = $gate . '.ready.' . $workerId;

    try {
        if (file_put_contents($ready, 'ready') === false) {
            $fail('Worker could not publish readiness.');
        }

        $deadline = microtime(true) + 10.0;
        while (!is_file($gate)) {
            if (microtime(true) >= $deadline) {
                $fail('Worker start gate timed out.');
            }
            usleep(1000);
        }

        $store->acquire($context, $state, $payment, $attempt);
        fwrite(STDOUT, "ACQUIRED\n");
    } catch (CheckoutFinalizationReservationAlreadyActive) {
        fwrite(STDOUT, "BLOCKED\n");
    } finally {
        @unlink($ready);
        $connection->close();
    }

    exit(0);
}

if ($mode !== 'parent') {
    $fail('Unknown concurrency contract mode.');
}
if (!function_exists('proc_open')) {
    $fail('proc_open is required for the process-level reservation concurrency contract.');
}

$connection = $connectionFactory();
$tableExists = (int) $connection->fetchOne(
    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
    [$table],
);
$assert($tableExists === 1, 'Installed finalization reservation table is missing.');

$context = Context::getContext();
$shopId = (int) ($context->shop->id ?? 1);
if ($shopId <= 0) {
    $shopId = 1;
}
$cartId = random_int(1_500_000_000, 2_000_000_000);
$customerA = random_int(1_000_000_000, 1_200_000_000);
$customerB = $customerA + 1;

$cleanup = static function () use ($connection, $table, $shopId, $cartId): void {
    $connection->executeStatement(
        sprintf('DELETE FROM `%s` WHERE id_shop = ? AND id_cart = ?', $table),
        [$shopId, $cartId],
    );
};

$runRace = static function (array $workers, string $label) use ($shopRoot, $expectedFamily, $fail): array {
    $gate = sys_get_temp_dir() . '/jzopc-reservation-race-' . bin2hex(random_bytes(8));
    $processes = [];
    $readyFiles = [];

    try {
        foreach ($workers as $workerId => $worker) {
            $readyFiles[] = $gate . '.ready.' . $workerId;
            $command = [
                PHP_BINARY,
                __FILE__,
                $shopRoot,
                $expectedFamily,
                'worker',
                (string) $workerId,
                (string) $worker['cart'],
                (string) $worker['shop'],
                (string) $worker['customer'],
                (string) $worker['state'],
                (string) $worker['payment'],
                (string) $worker['attempt'],
                $gate,
            ];
            $pipes = [];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) {
                $fail($label . ': unable to start worker process.');
            }
            $processes[] = [$process, $pipes];
        }

        $readyDeadline = microtime(true) + 15.0;
        do {
            $readyCount = count(array_filter($readyFiles, static fn (string $path): bool => is_file($path)));
            if ($readyCount === count($readyFiles)) {
                break;
            }
            if (microtime(true) >= $readyDeadline) {
                $fail(sprintf('%s: only %d/%d workers reached the acquisition barrier.', $label, $readyCount, count($readyFiles)));
            }
            usleep(1000);
        } while (true);

        if (file_put_contents($gate, 'go') === false) {
            $fail($label . ': unable to open start gate.');
        }

        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $stdout = trim((string) stream_get_contents($pipes[1]));
            $stderr = trim((string) stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);
            if ($status !== 0) {
                $fail(sprintf('%s: worker failed with exit=%d stderr=%s', $label, $status, $stderr));
            }
            $results[] = $stdout;
        }

        return $results;
    } finally {
        @unlink($gate);
        foreach ($readyFiles as $readyFile) {
            @unlink($readyFile);
        }
        foreach ($processes as [$process, $pipes]) {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
    }
};

try {
    $cleanup();

    $attemptA = bin2hex(random_bytes(16));
    $attemptB = bin2hex(random_bytes(16));
    $workers = [
        ['cart' => $cartId, 'shop' => $shopId, 'customer' => $customerA, 'state' => hash('sha256', 'a-' . $attemptA), 'payment' => 'runtime-race-a', 'attempt' => $attemptA],
        ['cart' => $cartId, 'shop' => $shopId, 'customer' => $customerA, 'state' => hash('sha256', 'b-' . $attemptB), 'payment' => 'runtime-race-b', 'attempt' => $attemptB],
    ];
    $results = $runRace($workers, 'distinct-attempt race');
    sort($results);
    $assert($results === ['ACQUIRED', 'BLOCKED'], 'Exactly one of two simultaneous distinct attempts must acquire the cart barrier.');

    $row = $connection->fetchAssociative(
        sprintf('SELECT id_customer, attempt_id FROM `%s` WHERE id_shop = ? AND id_cart = ?', $table),
        [$shopId, $cartId],
    );
    $assert(is_array($row), 'Distinct-attempt race did not leave exactly one reservation row.');
    $assert((int) ($row['id_customer'] ?? 0) === $customerA, 'Distinct-attempt race changed customer binding.');
    $assert(in_array((string) ($row['attempt_id'] ?? ''), [$attemptA, $attemptB], true), 'Distinct-attempt race stored an unknown winner attempt.');

    $cleanup();

    $attemptSame = bin2hex(random_bytes(16));
    $sameWorker = [
        'cart' => $cartId,
        'shop' => $shopId,
        'customer' => $customerA,
        'state' => hash('sha256', 'same-' . $attemptSame),
        'payment' => 'runtime-race-same',
        'attempt' => $attemptSame,
    ];
    $results = $runRace([$sameWorker, $sameWorker], 'idempotent replay race');
    sort($results);
    $assert($results === ['ACQUIRED', 'ACQUIRED'], 'Simultaneous identical attempts must both resolve idempotently.');
    $rowCount = (int) $connection->fetchOne(
        sprintf('SELECT COUNT(*) FROM `%s` WHERE id_shop = ? AND id_cart = ?', $table),
        [$shopId, $cartId],
    );
    $assert($rowCount === 1, 'Simultaneous identical attempts created more than one reservation row.');

    $cleanup();

    $attemptCustomerA = bin2hex(random_bytes(16));
    $attemptCustomerB = bin2hex(random_bytes(16));
    $results = $runRace([
        ['cart' => $cartId, 'shop' => $shopId, 'customer' => $customerA, 'state' => hash('sha256', 'ca-' . $attemptCustomerA), 'payment' => 'runtime-race-ca', 'attempt' => $attemptCustomerA],
        ['cart' => $cartId, 'shop' => $shopId, 'customer' => $customerB, 'state' => hash('sha256', 'cb-' . $attemptCustomerB), 'payment' => 'runtime-race-cb', 'attempt' => $attemptCustomerB],
    ], 'cross-customer race');
    sort($results);
    $assert($results === ['ACQUIRED', 'BLOCKED'], 'Same-cart cross-customer race must preserve one cart-level handoff barrier.');

    $rowCount = (int) $connection->fetchOne(
        sprintf('SELECT COUNT(*) FROM `%s` WHERE id_shop = ? AND id_cart = ?', $table),
        [$shopId, $cartId],
    );
    $assert($rowCount === 1, 'Cross-customer race created an invalid reservation multiplicity.');

    fwrite(STDOUT, sprintf(
        "Finalization reservation concurrency MariaDB contract OK: PrestaShop %s, cart=%d, distinct/idempotent/cross-customer process races verified\n",
        (string) _PS_VERSION_,
        $cartId,
    ));
} finally {
    try {
        $cleanup();
    } finally {
        $connection->close();
    }
}

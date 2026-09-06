<?php

declare(strict_types=1);

use Jzvikas\OnePageCheckout\Infrastructure\Persistence\CheckoutServerSelectionsSchema;

if ($argc < 3) {
    fwrite(STDERR, "Usage: php ActiveCheckoutPersistenceFailureControl.php <shop-root> <drop|restore>\n");
    exit(2);
}

$shopRootInput = rtrim((string) $argv[1], DIRECTORY_SEPARATOR);
$action = (string) $argv[2];

$fail = static function (string $message, int $code = 2): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit($code);
};

if (getenv('JZOPC_RUNTIME_ACTIVE_FIXTURE') !== '1') {
    $fail('Refusing active checkout persistence control without JZOPC_RUNTIME_ACTIVE_FIXTURE=1.');
}

$shopRoot = $shopRootInput !== '' ? realpath($shopRootInput) : false;
if (!is_string($shopRoot) || $shopRoot !== '/tmp/prestashop') {
    $fail('Active checkout persistence control is restricted to /tmp/prestashop.');
}
if (!is_file($shopRoot . '/config/config.inc.php')) {
    $fail('Installed PrestaShop runtime root is incomplete.');
}

require_once $shopRoot . '/config/config.inc.php';
require_once $shopRoot . '/modules/jzonepagecheckout/jzonepagecheckout.php';

$modulePath = realpath($shopRoot . '/modules/jzonepagecheckout/jzonepagecheckout.php');
if (!is_string($modulePath)
    || ($modulePath !== '/tmp/jzopc-active-fixture/jzonepagecheckout.php'
        && !str_starts_with($modulePath, '/tmp/jzopc-active-fixture-'))) {
    $fail('Persistence failure control refuses the production/source module tree.');
}

$schema = new CheckoutServerSelectionsSchema();

try {
    if ($action === 'drop') {
        if (!$schema->uninstall()) {
            $fail('Unable to drop checkout-selection schema for browser failure injection.', 3);
        }

        fwrite(STDOUT, "Active checkout persistence failure enabled.\n");
        exit(0);
    }

    if ($action === 'restore') {
        if (!$schema->install()) {
            $fail('Unable to restore checkout-selection schema after browser failure injection.', 3);
        }

        fwrite(STDOUT, "Active checkout persistence failure disabled.\n");
        exit(0);
    }
} catch (Throwable $exception) {
    $fail('Active checkout persistence control failed without exposing runtime state.', 3);
}

$fail('Unsupported active checkout persistence control action.');

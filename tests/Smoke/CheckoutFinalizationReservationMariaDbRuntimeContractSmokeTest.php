<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$workflow = file_get_contents($root . '/.github/workflows/prestashop-runtime.yml');
$runtime = file_get_contents($root . '/tests/Runtime/FinalizationReservationMariaDbContract.php');
$module = file_get_contents($root . '/jzonepagecheckout.php');

function assertFinalizationReservationMariaDbRuntimeContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

assertFinalizationReservationMariaDbRuntimeContract(is_string($workflow), 'runtime workflow must be readable');
assertFinalizationReservationMariaDbRuntimeContract(is_string($runtime), 'reservation MariaDB runtime contract must be readable');
assertFinalizationReservationMariaDbRuntimeContract(is_string($module), 'module source must be readable');

assertFinalizationReservationMariaDbRuntimeContract(
    str_contains($workflow, 'Execute PrestaShop 9.1 finalization reservation MariaDB contract'),
    'PrestaShop runtime workflow must wire the installed reservation database contract'
);
assertFinalizationReservationMariaDbRuntimeContract(
    str_contains($workflow, "if: matrix.family == '9.1'")
        && str_contains($workflow, 'php tests/Runtime/FinalizationReservationMariaDbContract.php'),
    'reservation database contract must stay scoped to the PrestaShop 9.1 production milestone'
);
assertFinalizationReservationMariaDbRuntimeContract(
    str_contains($runtime, 'new DbalCheckoutFinalizationReservationStore($connection, 900)'),
    'runtime contract must exercise the production DBAL reservation store with the production default TTL'
);
assertFinalizationReservationMariaDbRuntimeContract(
    str_contains($runtime, '$store->acquire($context, $stateA, $paymentA, $attemptA);')
        && str_contains($runtime, 'CheckoutFinalizationReservationAlreadyActive'),
    'runtime contract must exercise acquisition, idempotency and competing-attempt rejection'
);
assertFinalizationReservationMariaDbRuntimeContract(
    str_contains($runtime, '$cart->id_customer = $customerB;')
        && str_contains($runtime, '$store->releaseAttempt($context, $attemptA);'),
    'runtime contract must exercise customer-transition ownership and exact release behavior'
);
assertFinalizationReservationMariaDbRuntimeContract(
    str_contains($runtime, 'SET expires_at = UNIX_TIMESTAMP() - 1'),
    'runtime contract must exercise the installed MariaDB expiry recovery path using database time'
);
assertFinalizationReservationMariaDbRuntimeContract(
    !str_contains($runtime, 'validateOrder(')
        && !str_contains($runtime, 'new Order(')
        && !str_contains($runtime, 'Order::'),
    'reservation runtime contract must not manufacture or validate a Core order'
);
assertFinalizationReservationMariaDbRuntimeContract(
    str_contains($module, 'private const INTEGRATION_SHELL_READY = false;'),
    'adding the runtime contract must not open production checkout takeover'
);

fwrite(STDOUT, "Checkout finalization reservation MariaDB runtime contract source checks passed.\n");

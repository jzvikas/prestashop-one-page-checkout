<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$workflow = file_get_contents($root . '/.github/workflows/prestashop-runtime.yml');
$runtime = file_get_contents($root . '/tests/Runtime/FinalizationReservationConcurrencyMariaDbContract.php');
$module = file_get_contents($root . '/jzonepagecheckout.php');

function assertFinalizationReservationConcurrencyRuntimeContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

assertFinalizationReservationConcurrencyRuntimeContract(is_string($workflow), 'runtime workflow must be readable');
assertFinalizationReservationConcurrencyRuntimeContract(is_string($runtime), 'reservation concurrency runtime contract must be readable');
assertFinalizationReservationConcurrencyRuntimeContract(is_string($module), 'module source must be readable');

assertFinalizationReservationConcurrencyRuntimeContract(
    str_contains($workflow, 'Execute PrestaShop 9.1 finalization reservation process-concurrency contract')
        && str_contains($workflow, 'php tests/Runtime/FinalizationReservationConcurrencyMariaDbContract.php'),
    'PrestaShop runtime workflow must wire the process-level reservation concurrency contract'
);
assertFinalizationReservationConcurrencyRuntimeContract(
    substr_count($workflow, "if: matrix.family == '9.1'") >= 2,
    'reservation concurrency gate must stay scoped to the PrestaShop 9.1 production milestone'
);
assertFinalizationReservationConcurrencyRuntimeContract(
    str_contains($runtime, "if (!function_exists('proc_open'))")
        && str_contains($runtime, 'if ($mode === \'worker\')')
        && str_contains($runtime, "$ready = $gate . '.ready.' . $workerId;") === false,
    'literal interpolation sentinel must remain false'
);
assertFinalizationReservationConcurrencyRuntimeContract(
    str_contains($runtime, '$ready = $gate . \'.ready.\' . $workerId;')
        && str_contains($runtime, '$readyCount === count($readyFiles)')
        && str_contains($runtime, 'file_put_contents($gate, \'go\')'),
    'runtime contract must wait until every independent worker reaches the acquisition barrier before opening the common start gate'
);
assertFinalizationReservationConcurrencyRuntimeContract(
    str_contains($runtime, 'new DbalCheckoutFinalizationReservationStore($connection, 900)'),
    'runtime workers must exercise the production DBAL reservation store with the production default TTL'
);
assertFinalizationReservationConcurrencyRuntimeContract(
    str_contains($runtime, '$assert($results === [\'ACQUIRED\', \'BLOCKED\'], \'Exactly one of two simultaneous distinct attempts must acquire the cart barrier.\');'),
    'runtime contract must prove exactly one distinct simultaneous attempt acquires the cart barrier'
);
assertFinalizationReservationConcurrencyRuntimeContract(
    str_contains($runtime, '$assert($results === [\'ACQUIRED\', \'ACQUIRED\'], \'Simultaneous identical attempts must both resolve idempotently.\');'),
    'runtime contract must prove simultaneous identical replay resolves idempotently'
);
assertFinalizationReservationConcurrencyRuntimeContract(
    str_contains($runtime, "'cross-customer race'")
        && str_contains($runtime, 'Same-cart cross-customer race must preserve one cart-level handoff barrier.'),
    'runtime contract must exercise same-cart cross-customer contention'
);
assertFinalizationReservationConcurrencyRuntimeContract(
    !str_contains($runtime, 'validateOrder(')
        && !str_contains($runtime, 'new Order(')
        && !str_contains($runtime, 'Order::'),
    'reservation concurrency runtime contract must not manufacture or validate a Core order'
);
assertFinalizationReservationConcurrencyRuntimeContract(
    str_contains($module, 'private const INTEGRATION_SHELL_READY = false;'),
    'adding the concurrency runtime gate must not open production checkout takeover'
);

fwrite(STDOUT, "Checkout finalization reservation concurrency runtime contract source checks passed.\n");

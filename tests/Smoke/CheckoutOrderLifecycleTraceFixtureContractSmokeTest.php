<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$instrumenter = file_get_contents($root . '/tests/Runtime/InstrumentOrderLifecycleTraceFixture.php');
$builder = file_get_contents($root . '/tests/Runtime/build-active-checkout-fixture.sh');
$productionModule = file_get_contents($root . '/jzonepagecheckout.php');

if (!is_string($instrumenter) || $instrumenter === ''
    || !is_string($builder) || $builder === ''
    || !is_string($productionModule) || $productionModule === '') {
    fwrite(STDERR, "Missing order lifecycle trace fixture contract source.\n");
    exit(1);
}

$requiredInstrumentation = [
    "PHP_SAPI !== 'cli'",
    "getenv('JZOPC_RUNTIME_ACTIVE_FIXTURE') !== '1'",
    "\$argc !== 3",
    "realpath(\$argv[2])",
    "/tmp/jzopc-active-fixture",
    "private const INTEGRATION_SHELL_READY = false;",
    "private const INTEGRATION_SHELL_READY = true;",
    "hash_file('sha256', \$sourceModule)",
    "hash_equals(\$sourceHashBefore, \$sourceHashAfter)",
    "JZOPC_RUNTIME_ORDER_HOOK phase=enter",
    "JZOPC_RUNTIME_ORDER_HOOK phase=order_proven",
    "JZOPC_RUNTIME_ORDER_HOOK phase=cleanup_resolve",
    "JZOPC_RUNTIME_ORDER_HOOK phase=cleanup_begin",
    "JZOPC_RUNTIME_ORDER_HOOK phase=cleanup_end",
    "JZOPC_RUNTIME_ORDER_HOOK phase=cleanup_exception",
    "JZOPC_RUNTIME_ORDER_HOOK phase=exit",
];
foreach ($requiredInstrumentation as $needle) {
    if (!str_contains($instrumenter, $needle)) {
        fwrite(STDERR, "Order lifecycle trace fixture is missing required boundary: {$needle}\n");
        exit(1);
    }
}

$requiredBuilder = [
    'JZOPC_RUNTIME_ACTIVE_FIXTURE=1 php',
    'InstrumentOrderLifecycleTraceFixture.php',
    '$target_root',
    '$source_root',
];
foreach ($requiredBuilder as $needle) {
    if (!str_contains($builder, $needle)) {
        fwrite(STDERR, "Active runtime fixture does not install the order lifecycle trace: {$needle}\n");
        exit(1);
    }
}

$forbiddenInstrumentation = [
    'PaymentFree',
    'validateOrder(',
    'Order::add',
    'INSERT INTO',
    'UPDATE ',
    'DELETE FROM',
    '$_POST',
    '$_GET',
    '$_COOKIE',
    'QUERY_STRING',
    'HTTP_AUTHORIZATION',
    'secure_key',
    'csrf',
    'email',
    'firstname',
    'lastname',
];
foreach ($forbiddenInstrumentation as $needle) {
    if (stripos($instrumenter, $needle) !== false) {
        fwrite(STDERR, "Order lifecycle trace fixture crossed a write/sensitive-data boundary: {$needle}\n");
        exit(1);
    }
}

if (!str_contains($productionModule, 'private const INTEGRATION_SHELL_READY = false;')
    || str_contains($productionModule, 'JZOPC_RUNTIME_ORDER_HOOK')) {
    fwrite(STDERR, "Production module must remain closed and free of runtime hook tracing.\n");
    exit(1);
}

echo "Checkout order lifecycle trace fixture contract smoke test OK.\n";

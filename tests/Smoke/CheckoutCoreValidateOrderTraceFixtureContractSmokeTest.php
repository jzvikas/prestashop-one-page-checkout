<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$instrumenter = file_get_contents($root . '/tests/Runtime/InstrumentCoreValidateOrderTraceFixture.php');
$builder = file_get_contents($root . '/tests/Runtime/build-active-checkout-fixture.sh');
$productionModule = file_get_contents($root . '/jzonepagecheckout.php');

if (!is_string($instrumenter) || $instrumenter === ''
    || !is_string($builder) || $builder === ''
    || !is_string($productionModule) || $productionModule === '') {
    fwrite(STDERR, "Missing Core validateOrder trace fixture contract source.\n");
    exit(1);
}

$required = [
    "PHP_SAPI !== 'cli'",
    "getenv('JZOPC_RUNTIME_ACTIVE_FIXTURE') !== '1'",
    "\$argv[1] !== '/tmp/prestashop'",
    "realpath(\$argv[1])",
    "/classes/PaymentModule.php",
    "Hook::exec('actionValidateOrder', [",
    '$new_history->changeIdOrderState((int) $id_order_state, $order, true);',
    '$new_history->addWithemail(true, $extra_vars);',
    "'order_conf',",
    "'actionValidateOrderAfter',",
    'JZOPC_RUNTIME_CORE_VALIDATE phase=order_persisted',
    'JZOPC_RUNTIME_CORE_VALIDATE phase=validate_hook_begin',
    'JZOPC_RUNTIME_CORE_VALIDATE phase=validate_hook_end',
    'JZOPC_RUNTIME_CORE_VALIDATE phase=history_begin',
    'JZOPC_RUNTIME_CORE_VALIDATE phase=history_change_state_begin',
    'JZOPC_RUNTIME_CORE_VALIDATE phase=history_change_state_end',
    'JZOPC_RUNTIME_CORE_VALIDATE phase=history_add_with_email_begin',
    'JZOPC_RUNTIME_CORE_VALIDATE phase=history_add_with_email_end',
    'JZOPC_RUNTIME_CORE_VALIDATE phase=history_end',
    'JZOPC_RUNTIME_CORE_VALIDATE phase=confirmation_mail_section_begin',
    'JZOPC_RUNTIME_CORE_VALIDATE phase=confirmation_mail_section_end',
    'JZOPC_RUNTIME_CORE_VALIDATE phase=tax_update_begin',
    'JZOPC_RUNTIME_CORE_VALIDATE phase=tax_update_end',
    'JZOPC_RUNTIME_CORE_VALIDATE phase=stock_sync_begin',
    'JZOPC_RUNTIME_CORE_VALIDATE phase=stock_sync_end',
    'JZOPC_RUNTIME_CORE_VALIDATE phase=after_hook_begin',
    'JZOPC_RUNTIME_CORE_VALIDATE phase=after_hook_end',
];
foreach ($required as $needle) {
    if (!str_contains($instrumenter, $needle)) {
        fwrite(STDERR, "Core validateOrder trace fixture is missing required boundary: {$needle}\n");
        exit(1);
    }
}

foreach ([
    'InstrumentCoreValidateOrderTraceFixture.php',
    '/tmp/prestashop',
    'JZOPC_RUNTIME_ACTIVE_FIXTURE=1 php',
] as $needle) {
    if (!str_contains($builder, $needle)) {
        fwrite(STDERR, "Active runtime fixture does not install the Core validateOrder trace: {$needle}\n");
        exit(1);
    }
}

$forbidden = [
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
    '$this->context->customer->email',
    '$this->context->customer->firstname',
    '$this->context->customer->lastname',
    'error_log($',
];
foreach ($forbidden as $needle) {
    if (stripos($instrumenter, $needle) !== false) {
        fwrite(STDERR, "Core validateOrder trace fixture crossed a write/sensitive-data boundary: {$needle}\n");
        exit(1);
    }
}

if (!str_contains($productionModule, 'private const INTEGRATION_SHELL_READY = false;')
    || str_contains($productionModule, 'JZOPC_RUNTIME_CORE_VALIDATE')) {
    fwrite(STDERR, "Production module must remain closed and free of Core validateOrder tracing.\n");
    exit(1);
}

echo "Checkout Core validateOrder trace fixture contract smoke test OK.\n";

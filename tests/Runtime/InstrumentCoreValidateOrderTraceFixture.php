<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Core validateOrder trace fixture is CLI-only.\n");
    exit(2);
}

if (getenv('JZOPC_RUNTIME_ACTIVE_FIXTURE') !== '1') {
    fwrite(STDERR, "Refusing Core validateOrder trace instrumentation without JZOPC_RUNTIME_ACTIVE_FIXTURE=1.\n");
    exit(2);
}

if ($argc !== 2 || $argv[1] !== '/tmp/prestashop') {
    fwrite(STDERR, "Core validateOrder trace target must be exactly /tmp/prestashop.\n");
    exit(2);
}

$coreRoot = realpath($argv[1]);
if ($coreRoot !== '/tmp/prestashop') {
    fwrite(STDERR, "Core validateOrder trace target must resolve to /tmp/prestashop.\n");
    exit(2);
}

$coreFile = $coreRoot . '/classes/PaymentModule.php';
$source = is_file($coreFile) ? file_get_contents($coreFile) : false;
if (!is_string($source) || $source === '') {
    fwrite(STDERR, "PrestaShop PaymentModule.php is unavailable.\n");
    exit(3);
}

if (str_contains($source, 'JZOPC_RUNTIME_CORE_VALIDATE')) {
    fwrite(STDERR, "PrestaShop validateOrder fixture is already instrumented.\n");
    exit(3);
}

$requiredCoreSemantics = [
    "Hook::exec('actionValidateOrder', [",
    '$new_history->addWithemail(true, $extra_vars);',
    "Mail::Send(\n",
    '$order->updateOrderDetailTax();',
    '(new StockManager())->updatePhysicalProductQuantity(',
    "'actionValidateOrderAfter',",
];
foreach ($requiredCoreSemantics as $needle) {
    if (substr_count($source, $needle) !== 1) {
        fwrite(STDERR, "Unexpected PrestaShop 9.1.5 validateOrder source boundary.\n");
        exit(3);
    }
}

$replacements = [
    "        // Next !\n        \$products = \$this->context->cart->getProducts();" =>
        "        error_log('JZOPC_RUNTIME_CORE_VALIDATE phase=order_persisted');\n\n        // Next !\n        \$products = \$this->context->cart->getProducts();",
    "            // Hook validate order\n            Hook::exec('actionValidateOrder', [" =>
        "            // Hook validate order\n            error_log('JZOPC_RUNTIME_CORE_VALIDATE phase=validate_hook_begin');\n            Hook::exec('actionValidateOrder', [",
    "                'orderStatus' => \$order_status,\n            ]);\n\n            if (\$order_status->logable)" =>
        "                'orderStatus' => \$order_status,\n            ]);\n            error_log('JZOPC_RUNTIME_CORE_VALIDATE phase=validate_hook_end');\n\n            if (\$order_status->logable)",
    "            // Set the order status\n            \$new_history = new OrderHistory();" =>
        "            // Set the order status\n            error_log('JZOPC_RUNTIME_CORE_VALIDATE phase=history_begin');\n            \$new_history = new OrderHistory();",
    "            \$new_history->addWithemail(true, \$extra_vars);\n\n            // Switch to back order if needed" =>
        "            \$new_history->addWithemail(true, \$extra_vars);\n            error_log('JZOPC_RUNTIME_CORE_VALIDATE phase=history_end');\n\n            // Switch to back order if needed",
    "            // Send an e-mail to customer (one order = one email)\n            if (\$id_order_state != Configuration::get('PS_OS_ERROR')" =>
        "            // Send an e-mail to customer (one order = one email)\n            error_log('JZOPC_RUNTIME_CORE_VALIDATE phase=confirmation_mail_section_begin');\n            if (\$id_order_state != Configuration::get('PS_OS_ERROR')",
    "            \$order->updateOrderDetailTax();" =>
        "            error_log('JZOPC_RUNTIME_CORE_VALIDATE phase=confirmation_mail_section_end');\n            error_log('JZOPC_RUNTIME_CORE_VALIDATE phase=tax_update_begin');\n            \$order->updateOrderDetailTax();\n            error_log('JZOPC_RUNTIME_CORE_VALIDATE phase=tax_update_end');",
    "            (new StockManager())->updatePhysicalProductQuantity(" =>
        "            error_log('JZOPC_RUNTIME_CORE_VALIDATE phase=stock_sync_begin');\n            (new StockManager())->updatePhysicalProductQuantity(",
    "                (int) \$order->id\n            );\n        } // End foreach \$order_detail_list" =>
        "                (int) \$order->id\n            );\n            error_log('JZOPC_RUNTIME_CORE_VALIDATE phase=stock_sync_end');\n        } // End foreach \$order_detail_list",
    "        Hook::exec(\n            'actionValidateOrderAfter'," =>
        "        error_log('JZOPC_RUNTIME_CORE_VALIDATE phase=after_hook_begin');\n        Hook::exec(\n            'actionValidateOrderAfter',",
    "            ]\n        );\n\n        return true;" =>
        "            ]\n        );\n        error_log('JZOPC_RUNTIME_CORE_VALIDATE phase=after_hook_end');\n\n        return true;",
];

$updated = $source;
foreach ($replacements as $needle => $replacement) {
    if (substr_count($updated, $needle) !== 1) {
        fwrite(STDERR, "PrestaShop validateOrder trace expected a unique source boundary.\n");
        exit(3);
    }
    $updated = str_replace($needle, $replacement, $updated, $count);
    if ($count !== 1) {
        fwrite(STDERR, "Unable to instrument PrestaShop validateOrder boundary.\n");
        exit(3);
    }
}

foreach ([
    'phase=order_persisted',
    'phase=validate_hook_begin',
    'phase=validate_hook_end',
    'phase=history_begin',
    'phase=history_end',
    'phase=confirmation_mail_section_begin',
    'phase=confirmation_mail_section_end',
    'phase=tax_update_begin',
    'phase=tax_update_end',
    'phase=stock_sync_begin',
    'phase=stock_sync_end',
    'phase=after_hook_begin',
    'phase=after_hook_end',
] as $marker) {
    if (!str_contains($updated, 'JZOPC_RUNTIME_CORE_VALIDATE ' . $marker)) {
        fwrite(STDERR, "PrestaShop validateOrder trace is incomplete.\n");
        exit(3);
    }
}

if (file_put_contents($coreFile, $updated) === false) {
    fwrite(STDERR, "Unable to write disposable PrestaShop validateOrder trace fixture.\n");
    exit(3);
}

fwrite(STDOUT, "Disposable PrestaShop validateOrder trace instrumentation installed.\n");

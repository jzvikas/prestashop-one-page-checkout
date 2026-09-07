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
    '$new_history->changeIdOrderState((int) $id_order_state, $order, true);',
    '$new_history->addWithemail(true, $extra_vars);',
    "'order_conf',",
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
    "            \$new_history->changeIdOrderState((int) \$id_order_state, \$order, true);" =>
        "            error_log('JZOPC_RUNTIME_CORE_VALIDATE phase=history_change_state_begin');\n            \$new_history->changeIdOrderState((int) \$id_order_state, \$order, true);\n            error_log('JZOPC_RUNTIME_CORE_VALIDATE phase=history_change_state_end');",
    "            \$new_history->addWithemail(true, \$extra_vars);\n\n            // Switch to back order if needed" =>
        "            error_log('JZOPC_RUNTIME_CORE_VALIDATE phase=history_add_with_email_begin');\n            \$new_history->addWithemail(true, \$extra_vars);\n            error_log('JZOPC_RUNTIME_CORE_VALIDATE phase=history_add_with_email_end');\n            error_log('JZOPC_RUNTIME_CORE_VALIDATE phase=history_end');\n\n            // Switch to back order if needed",
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
    'phase=history_change_state_begin',
    'phase=history_change_state_end',
    'phase=history_add_with_email_begin',
    'phase=history_add_with_email_end',
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

$orderHistoryFile = $coreRoot . '/classes/order/OrderHistory.php';
$orderHistorySource = is_file($orderHistoryFile) ? file_get_contents($orderHistoryFile) : false;
if (!is_string($orderHistorySource) || $orderHistorySource === '') {
    fwrite(STDERR, "PrestaShop OrderHistory.php is unavailable.\n");
    exit(3);
}
if (str_contains($orderHistorySource, 'JZOPC_RUNTIME_CORE_HISTORY')) {
    fwrite(STDERR, "PrestaShop OrderHistory fixture is already instrumented.\n");
    exit(3);
}

$requiredOrderHistorySemantics = [
    'public function addWithemail($autodate = true, $template_vars = false, ?Context $context = null)',
    'if (!$this->add($autodate)) {',
    'Order::cleanHistoryCache();',
    'if (!$this->sendEmail($order, $template_vars)) {',
    'public function sendEmail($order, $template_vars = false)',
    '$result = Db::getInstance()->getRow(',
    'if (!Mail::Send(',
    'public function add($autodate = true, $null_values = false)',
    'if (!parent::add($autodate)) {',
    "\$order->current_state = \$this->id_order_state;\n        \$order->update();",
    "Hook::exec('actionOrderHistoryAddAfter', ['order_history' => \$this], null, false, true, false, \$order->id_shop);",
    '$invoice = $order->getInvoicesCollection();',
    "Hook::exec('actionPDFInvoiceRender', ['order_invoice_list' => \$invoice]);",
    '$pdf = new PDF($invoice, PDF::TEMPLATE_INVOICE, $context->smarty);',
    "\$file_attachement['invoice']['content'] = \$pdf->render(false);",
    "\$file_attachement['invoice']['name'] = \$pdf->getFilename();",
    '$pdf = new PDF($invoice, PDF::TEMPLATE_DELIVERY_SLIP, $context->smarty);',
    "\$file_attachement['delivery']['content'] = \$pdf->render(false);",
    "\$file_attachement['delivery']['name'] = \$pdf->getFilename();",
    "\$context->language = \$currentLanguage;\n                    \$context->getTranslator()->setLocale(\$currentLanguage->locale);",
];
foreach ($requiredOrderHistorySemantics as $needle) {
    if (substr_count($orderHistorySource, $needle) !== 1) {
        fwrite(STDERR, "Unexpected PrestaShop 9.1.5 OrderHistory source boundary.\n");
        exit(3);
    }
}

$orderHistoryReplacements = [
    "        if (!\$this->add(\$autodate)) {\n            return false;\n        }\n        Order::cleanHistoryCache();" =>
        "        error_log('JZOPC_RUNTIME_CORE_HISTORY phase=add_begin');\n        if (!\$this->add(\$autodate)) {\n            return false;\n        }\n        error_log('JZOPC_RUNTIME_CORE_HISTORY phase=add_end');\n        error_log('JZOPC_RUNTIME_CORE_HISTORY phase=cache_clean_begin');\n        Order::cleanHistoryCache();\n        error_log('JZOPC_RUNTIME_CORE_HISTORY phase=cache_clean_end');",
    "        if (!\$this->sendEmail(\$order, \$template_vars)) {\n            return false;\n        }" =>
        "        error_log('JZOPC_RUNTIME_CORE_HISTORY phase=send_email_begin');\n        if (!\$this->sendEmail(\$order, \$template_vars)) {\n            return false;\n        }\n        error_log('JZOPC_RUNTIME_CORE_HISTORY phase=send_email_end');",
    "        \$result = Db::getInstance()->getRow('" =>
        "        error_log('JZOPC_RUNTIME_CORE_HISTORY phase=email_lookup_begin');\n        \$result = Db::getInstance()->getRow('",
    "            WHERE oh.`id_order_history` = ' . (int) \$this->id . ' AND os.`send_email` = 1');\n        if (isset(\$result['template'])" =>
        "            WHERE oh.`id_order_history` = ' . (int) \$this->id . ' AND os.`send_email` = 1');\n        error_log('JZOPC_RUNTIME_CORE_HISTORY phase=email_lookup_end');\n        if (isset(\$result['template'])",
    "            ShopUrl::cacheMainDomainForShop(\$order->id_shop);\n\n            \$topic = \$result['osname'];" =>
        "            error_log('JZOPC_RUNTIME_CORE_HISTORY phase=email_prepare_begin');\n            ShopUrl::cacheMainDomainForShop(\$order->id_shop);\n\n            \$topic = \$result['osname'];",
    "            if (Validate::isLoadedObject(\$order)) {\n                // Attach invoice and / or delivery-slip if they exists and status is set to attach them" =>
        "            error_log('JZOPC_RUNTIME_CORE_HISTORY phase=email_prepare_end');\n            if (Validate::isLoadedObject(\$order)) {\n                // Attach invoice and / or delivery-slip if they exists and status is set to attach them\n                error_log('JZOPC_RUNTIME_CORE_HISTORY phase=attachment_prepare_begin');",
    "                    \$invoice = \$order->getInvoicesCollection();" =>
        "                    error_log('JZOPC_RUNTIME_CORE_HISTORY phase=attachment_invoice_collection_begin');\n                    \$invoice = \$order->getInvoicesCollection();\n                    error_log('JZOPC_RUNTIME_CORE_HISTORY phase=attachment_invoice_collection_end');",
    "                        Hook::exec('actionPDFInvoiceRender', ['order_invoice_list' => \$invoice]);" =>
        "                        error_log('JZOPC_RUNTIME_CORE_HISTORY phase=attachment_invoice_hook_begin');\n                        Hook::exec('actionPDFInvoiceRender', ['order_invoice_list' => \$invoice]);\n                        error_log('JZOPC_RUNTIME_CORE_HISTORY phase=attachment_invoice_hook_end');",
    "                        \$pdf = new PDF(\$invoice, PDF::TEMPLATE_INVOICE, \$context->smarty);" =>
        "                        error_log('JZOPC_RUNTIME_CORE_HISTORY phase=attachment_invoice_pdf_construct_begin');\n                        \$pdf = new PDF(\$invoice, PDF::TEMPLATE_INVOICE, \$context->smarty);\n                        error_log('JZOPC_RUNTIME_CORE_HISTORY phase=attachment_invoice_pdf_construct_end');",
    "                        \$file_attachement['invoice']['content'] = \$pdf->render(false);" =>
        "                        error_log('JZOPC_RUNTIME_CORE_HISTORY phase=attachment_invoice_pdf_render_begin');\n                        \$file_attachement['invoice']['content'] = \$pdf->render(false);\n                        error_log('JZOPC_RUNTIME_CORE_HISTORY phase=attachment_invoice_pdf_render_end');",
    "                        \$file_attachement['invoice']['name'] = \$pdf->getFilename();" =>
        "                        error_log('JZOPC_RUNTIME_CORE_HISTORY phase=attachment_invoice_filename_begin');\n                        \$file_attachement['invoice']['name'] = \$pdf->getFilename();\n                        error_log('JZOPC_RUNTIME_CORE_HISTORY phase=attachment_invoice_filename_end');",
    "                        \$pdf = new PDF(\$invoice, PDF::TEMPLATE_DELIVERY_SLIP, \$context->smarty);" =>
        "                        error_log('JZOPC_RUNTIME_CORE_HISTORY phase=attachment_delivery_pdf_construct_begin');\n                        \$pdf = new PDF(\$invoice, PDF::TEMPLATE_DELIVERY_SLIP, \$context->smarty);\n                        error_log('JZOPC_RUNTIME_CORE_HISTORY phase=attachment_delivery_pdf_construct_end');",
    "                        \$file_attachement['delivery']['content'] = \$pdf->render(false);" =>
        "                        error_log('JZOPC_RUNTIME_CORE_HISTORY phase=attachment_delivery_pdf_render_begin');\n                        \$file_attachement['delivery']['content'] = \$pdf->render(false);\n                        error_log('JZOPC_RUNTIME_CORE_HISTORY phase=attachment_delivery_pdf_render_end');",
    "                        \$file_attachement['delivery']['name'] = \$pdf->getFilename();" =>
        "                        error_log('JZOPC_RUNTIME_CORE_HISTORY phase=attachment_delivery_filename_begin');\n                        \$file_attachement['delivery']['name'] = \$pdf->getFilename();\n                        error_log('JZOPC_RUNTIME_CORE_HISTORY phase=attachment_delivery_filename_end');",
    "                    \$context->language = \$currentLanguage;\n                    \$context->getTranslator()->setLocale(\$currentLanguage->locale);" =>
        "                    error_log('JZOPC_RUNTIME_CORE_HISTORY phase=attachment_language_restore_begin');\n                    \$context->language = \$currentLanguage;\n                    \$context->getTranslator()->setLocale(\$currentLanguage->locale);\n                    error_log('JZOPC_RUNTIME_CORE_HISTORY phase=attachment_language_restore_end');",
    "                if (!Mail::Send(" =>
        "                error_log('JZOPC_RUNTIME_CORE_HISTORY phase=attachment_prepare_end');\n                error_log('JZOPC_RUNTIME_CORE_HISTORY phase=status_mail_begin');\n                if (!Mail::Send(",
    "                )) {\n                    return false;\n                }\n            }\n\n            ShopUrl::resetMainDomainCache();" =>
        "                )) {\n                    return false;\n                }\n                error_log('JZOPC_RUNTIME_CORE_HISTORY phase=status_mail_end');\n            }\n\n            ShopUrl::resetMainDomainCache();",
    "        if (!parent::add(\$autodate)) {\n            return false;\n        }" =>
        "        error_log('JZOPC_RUNTIME_CORE_HISTORY phase=history_row_persist_begin');\n        if (!parent::add(\$autodate)) {\n            return false;\n        }\n        error_log('JZOPC_RUNTIME_CORE_HISTORY phase=history_row_persist_end');",
    "        \$order->current_state = \$this->id_order_state;\n        \$order->update();" =>
        "        \$order->current_state = \$this->id_order_state;\n        error_log('JZOPC_RUNTIME_CORE_HISTORY phase=order_state_update_begin');\n        \$order->update();\n        error_log('JZOPC_RUNTIME_CORE_HISTORY phase=order_state_update_end');",
    "        Hook::exec('actionOrderHistoryAddAfter', ['order_history' => \$this], null, false, true, false, \$order->id_shop);" =>
        "        error_log('JZOPC_RUNTIME_CORE_HISTORY phase=history_hook_begin');\n        Hook::exec('actionOrderHistoryAddAfter', ['order_history' => \$this], null, false, true, false, \$order->id_shop);\n        error_log('JZOPC_RUNTIME_CORE_HISTORY phase=history_hook_end');",
];

$orderHistoryUpdated = $orderHistorySource;
foreach ($orderHistoryReplacements as $needle => $replacement) {
    if (substr_count($orderHistoryUpdated, $needle) !== 1) {
        fwrite(STDERR, "PrestaShop OrderHistory trace expected a unique source boundary.\n");
        exit(3);
    }
    $orderHistoryUpdated = str_replace($needle, $replacement, $orderHistoryUpdated, $count);
    if ($count !== 1) {
        fwrite(STDERR, "Unable to instrument PrestaShop OrderHistory boundary.\n");
        exit(3);
    }
}

foreach ([
    'phase=add_begin',
    'phase=add_end',
    'phase=cache_clean_begin',
    'phase=cache_clean_end',
    'phase=send_email_begin',
    'phase=send_email_end',
    'phase=email_lookup_begin',
    'phase=email_lookup_end',
    'phase=email_prepare_begin',
    'phase=email_prepare_end',
    'phase=attachment_prepare_begin',
    'phase=attachment_invoice_collection_begin',
    'phase=attachment_invoice_collection_end',
    'phase=attachment_invoice_hook_begin',
    'phase=attachment_invoice_hook_end',
    'phase=attachment_invoice_pdf_construct_begin',
    'phase=attachment_invoice_pdf_construct_end',
    'phase=attachment_invoice_pdf_render_begin',
    'phase=attachment_invoice_pdf_render_end',
    'phase=attachment_invoice_filename_begin',
    'phase=attachment_invoice_filename_end',
    'phase=attachment_delivery_pdf_construct_begin',
    'phase=attachment_delivery_pdf_construct_end',
    'phase=attachment_delivery_pdf_render_begin',
    'phase=attachment_delivery_pdf_render_end',
    'phase=attachment_delivery_filename_begin',
    'phase=attachment_delivery_filename_end',
    'phase=attachment_language_restore_begin',
    'phase=attachment_language_restore_end',
    'phase=attachment_prepare_end',
    'phase=status_mail_begin',
    'phase=status_mail_end',
    'phase=history_row_persist_begin',
    'phase=history_row_persist_end',
    'phase=order_state_update_begin',
    'phase=order_state_update_end',
    'phase=history_hook_begin',
    'phase=history_hook_end',
] as $marker) {
    if (!str_contains($orderHistoryUpdated, 'JZOPC_RUNTIME_CORE_HISTORY ' . $marker)) {
        fwrite(STDERR, "PrestaShop OrderHistory trace is incomplete.\n");
        exit(3);
    }
}

if (file_put_contents($orderHistoryFile, $orderHistoryUpdated) === false) {
    fwrite(STDERR, "Unable to write disposable PrestaShop OrderHistory trace fixture.\n");
    exit(3);
}

fwrite(STDOUT, "Disposable PrestaShop validateOrder and OrderHistory trace instrumentation installed.\n");

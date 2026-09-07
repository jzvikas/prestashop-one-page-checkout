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
    "/classes/order/OrderHistory.php",
    "Hook::exec('actionValidateOrder', [",
    '$new_history->changeIdOrderState((int) $id_order_state, $order, true);',
    '$new_history->addWithemail(true, $extra_vars);',
    "'order_conf',",
    "'actionValidateOrderAfter',",
    'public function addWithemail($autodate = true, $template_vars = false, ?Context $context = null)',
    'if (!$this->add($autodate)) {',
    'Order::cleanHistoryCache();',
    'if (!$this->sendEmail($order, $template_vars)) {',
    'public function sendEmail($order, $template_vars = false)',
    '$result = Db::getInstance()->getRow(',
    '$invoice = $order->getInvoicesCollection();',
    "Hook::exec('actionPDFInvoiceRender', ['order_invoice_list' => \$invoice]);",
    '$pdf = new PDF($invoice, PDF::TEMPLATE_INVOICE, $context->smarty);',
    "\$file_attachement['invoice']['content'] = \$pdf->render(false);",
    "\$file_attachement['invoice']['name'] = \$pdf->getFilename();",
    '$pdf = new PDF($invoice, PDF::TEMPLATE_DELIVERY_SLIP, $context->smarty);',
    "\$file_attachement['delivery']['content'] = \$pdf->render(false);",
    "\$file_attachement['delivery']['name'] = \$pdf->getFilename();",
    'if (!Mail::Send(',
    'public function add($autodate = true, $null_values = false)',
    'if (!parent::add($autodate)) {',
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
    'JZOPC_RUNTIME_CORE_HISTORY phase=add_begin',
    'JZOPC_RUNTIME_CORE_HISTORY phase=add_end',
    'JZOPC_RUNTIME_CORE_HISTORY phase=cache_clean_begin',
    'JZOPC_RUNTIME_CORE_HISTORY phase=cache_clean_end',
    'JZOPC_RUNTIME_CORE_HISTORY phase=send_email_begin',
    'JZOPC_RUNTIME_CORE_HISTORY phase=send_email_end',
    'JZOPC_RUNTIME_CORE_HISTORY phase=email_lookup_begin',
    'JZOPC_RUNTIME_CORE_HISTORY phase=email_lookup_end',
    'JZOPC_RUNTIME_CORE_HISTORY phase=email_prepare_begin',
    'JZOPC_RUNTIME_CORE_HISTORY phase=email_prepare_end',
    'JZOPC_RUNTIME_CORE_HISTORY phase=attachment_prepare_begin',
    'JZOPC_RUNTIME_CORE_HISTORY phase=attachment_invoice_collection_begin',
    'JZOPC_RUNTIME_CORE_HISTORY phase=attachment_invoice_collection_end',
    'JZOPC_RUNTIME_CORE_HISTORY phase=attachment_invoice_hook_begin',
    'JZOPC_RUNTIME_CORE_HISTORY phase=attachment_invoice_hook_end',
    'JZOPC_RUNTIME_CORE_HISTORY phase=attachment_invoice_pdf_construct_begin',
    'JZOPC_RUNTIME_CORE_HISTORY phase=attachment_invoice_pdf_construct_end',
    'JZOPC_RUNTIME_CORE_HISTORY phase=attachment_invoice_pdf_render_begin',
    'JZOPC_RUNTIME_CORE_HISTORY phase=attachment_invoice_pdf_render_end',
    'JZOPC_RUNTIME_CORE_HISTORY phase=attachment_invoice_filename_begin',
    'JZOPC_RUNTIME_CORE_HISTORY phase=attachment_invoice_filename_end',
    'JZOPC_RUNTIME_CORE_HISTORY phase=attachment_delivery_pdf_construct_begin',
    'JZOPC_RUNTIME_CORE_HISTORY phase=attachment_delivery_pdf_construct_end',
    'JZOPC_RUNTIME_CORE_HISTORY phase=attachment_delivery_pdf_render_begin',
    'JZOPC_RUNTIME_CORE_HISTORY phase=attachment_delivery_pdf_render_end',
    'JZOPC_RUNTIME_CORE_HISTORY phase=attachment_delivery_filename_begin',
    'JZOPC_RUNTIME_CORE_HISTORY phase=attachment_delivery_filename_end',
    'JZOPC_RUNTIME_CORE_HISTORY phase=attachment_language_restore_begin',
    'JZOPC_RUNTIME_CORE_HISTORY phase=attachment_language_restore_end',
    'JZOPC_RUNTIME_CORE_HISTORY phase=attachment_prepare_end',
    'JZOPC_RUNTIME_CORE_HISTORY phase=status_mail_begin',
    'JZOPC_RUNTIME_CORE_HISTORY phase=status_mail_end',
    'JZOPC_RUNTIME_CORE_HISTORY phase=history_row_persist_begin',
    'JZOPC_RUNTIME_CORE_HISTORY phase=history_row_persist_end',
    'JZOPC_RUNTIME_CORE_HISTORY phase=order_state_update_begin',
    'JZOPC_RUNTIME_CORE_HISTORY phase=order_state_update_end',
    'JZOPC_RUNTIME_CORE_HISTORY phase=history_hook_begin',
    'JZOPC_RUNTIME_CORE_HISTORY phase=history_hook_end',
];
foreach ($required as $needle) {
    if (!str_contains($instrumenter, $needle)) {
        fwrite(STDERR, "Core validateOrder/OrderHistory trace fixture is missing required boundary: {$needle}\n");
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
        fwrite(STDERR, "Core validateOrder/OrderHistory trace fixture crossed a write/sensitive-data boundary: {$needle}\n");
        exit(1);
    }
}

if (!str_contains($productionModule, 'private const INTEGRATION_SHELL_READY = false;')
    || str_contains($productionModule, 'JZOPC_RUNTIME_CORE_VALIDATE')
    || str_contains($productionModule, 'JZOPC_RUNTIME_CORE_HISTORY')) {
    fwrite(STDERR, "Production module must remain closed and free of Core runtime tracing.\n");
    exit(1);
}

echo "Checkout Core validateOrder/OrderHistory trace fixture contract smoke test OK.\n";

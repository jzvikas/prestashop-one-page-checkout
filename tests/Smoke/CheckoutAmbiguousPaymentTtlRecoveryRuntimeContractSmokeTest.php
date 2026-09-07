<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$browser = file_get_contents($root . '/tests/Browser/native-payment-ttl-recovery-browser-contract.mjs');
$ambiguous = file_get_contents($root . '/tests/Browser/native-payment-ambiguous-handoff-browser-contract.mjs');
$control = file_get_contents($root . '/tests/Runtime/AmbiguousPaymentReservationExpiryControl.php');
$workflow = file_get_contents($root . '/.github/workflows/native-payment-runtime.yml');

if (!is_string($browser) || !is_string($ambiguous) || !is_string($control) || !is_string($workflow)) {
    throw new RuntimeException('Unable to read ambiguity TTL recovery runtime sources.');
}

$requiredBrowser = [
    'browser.newContext({ storageState: storageStatePath })',
    'binding.cartId !== expectedCartId',
    "binding.reserved !== '0'",
    'isValidation(request.url())',
    'isOrderConfirmation(url.toString())',
    'trace.ambiguous !== 0',
    'JZOPC_RECOVERED_ORDER_CART_ID=',
    'JZOPC_RECOVERED_ORDER_ID=',
];
foreach ($requiredBrowser as $needle) {
    if (!str_contains($browser, $needle)) {
        throw new RuntimeException(sprintf('TTL recovery browser contract is missing %s.', $needle));
    }
}

if (!str_contains($ambiguous, 'context.storageState({ path: storageStatePath })')
    || !str_contains($ambiguous, 'chmod(storageStatePath, 0o600)')) {
    throw new RuntimeException('Ambiguity browser state is not persisted with an explicit 0600 boundary.');
}

$requiredControl = [
    "getenv('JZOPC_RUNTIME_ACTIVE_FIXTURE') !== '1'",
    '$shopRoot !== \'/tmp/prestashop\'',
    "str_starts_with(\$modulePath, '/tmp/jzopc-active-fixture-')",
    'SELECT COUNT(*) FROM `%sorders` WHERE id_cart = ?',
    'SET expires_at = UNIX_TIMESTAMP() - 1',
    'expires_at > UNIX_TIMESTAMP()',
];
foreach ($requiredControl as $needle) {
    if (!str_contains($control, $needle)) {
        throw new RuntimeException(sprintf('TTL expiry control is missing %s.', $needle));
    }
}

if (str_contains($control, 'validateOrder(') || str_contains($browser, 'validateOrder(')) {
    throw new RuntimeException('TTL recovery gate must not create orders directly.');
}

$workflowNeedles = [
    'JZOPC_AMBIGUOUS_STORAGE_STATE_PATH: /tmp/jzopc-ambiguous-browser-state.json',
    'AmbiguousPaymentReservationExpiryControl.php',
    'native-payment-ttl-recovery-browser-contract.mjs',
    'Verify recovered Core order ownership and OPC cleanup',
    'rm -f /tmp/jzopc-ambiguous-browser-state.json',
];
foreach ($workflowNeedles as $needle) {
    if (!str_contains($workflow, $needle)) {
        throw new RuntimeException(sprintf('Native payment workflow is missing %s.', $needle));
    }
}

$ambiguousPos = strpos($workflow, 'Verify ambiguous handoff preserved reservation');
$expirePos = strpos($workflow, 'Expire ambiguous reservation using database time');
$recoverPos = strpos($workflow, 'Recover same cart and complete native payment after TTL expiry');
$cleanupPos = strpos($workflow, 'Verify recovered Core order ownership and OPC cleanup');
if ($ambiguousPos === false || $expirePos === false || $recoverPos === false || $cleanupPos === false
    || !($ambiguousPos < $expirePos && $expirePos < $recoverPos && $recoverPos < $cleanupPos)) {
    throw new RuntimeException('Ambiguity TTL recovery workflow order is not fail-closed.');
}

fwrite(STDOUT, "Ambiguous payment TTL recovery runtime source contract OK\n");

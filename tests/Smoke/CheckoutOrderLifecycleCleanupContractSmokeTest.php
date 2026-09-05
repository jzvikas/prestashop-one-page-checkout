<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$module = file_get_contents($root . '/jzonepagecheckout.php');
$cleanup = file_get_contents($root . '/src/Checkout/Finalization/CheckoutOrderLifecycleCleanup.php');
$services = file_get_contents($root . '/config/common/services.yml');
$upgrade = file_get_contents($root . '/upgrade/upgrade-0.4.0.php');

function assertOrderCleanupContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

foreach ([$module, $cleanup, $services, $upgrade] as $source) {
    assertOrderCleanupContract(is_string($source), 'post-order cleanup contract source must be readable');
}

assertOrderCleanupContract(str_contains($module, 'hookActionValidateOrderAfter'), 'module must implement the Core post-order lifecycle hook');
assertOrderCleanupContract(str_contains($module, '$params[\'cart\'] ?? null'), 'post-order cleanup must use the Core hook cart payload');
assertOrderCleanupContract(str_contains($module, 'hasCreatedOrderForCart'), 'cleanup must verify a real Core order exists for the hook cart');
assertOrderCleanupContract(str_contains($module, 'Order::getIdByCartId($cartId)'), 'cleanup must retain a Core DB fallback for valid order creation');
assertOrderCleanupContract(str_contains($module, 'CheckoutOrderLifecycleCleanup::class'), 'module hook must delegate cleanup to a narrow service');
assertOrderCleanupContract(str_contains($module, 'catch (Throwable $exception)'), 'post-order cleanup failure must not escape into an already-created payment flow');
assertOrderCleanupContract(!str_contains($module, 'validateOrder('), 'OPC module hook must never create or validate an order itself');

assertOrderCleanupContract(str_contains($cleanup, 'jzopc_checkout_finalization'), 'successful Core order must clear finalization reservation state');
assertOrderCleanupContract(str_contains($cleanup, 'jzopc_checkout_selection'), 'successful Core order must clear payment/agreement selection state');
assertOrderCleanupContract(str_contains($cleanup, 'WHERE id_shop = ? AND id_cart = ?'), 'cleanup must be scoped to authoritative shop and cart identifiers');
assertOrderCleanupContract(str_contains($cleanup, 'executeStatement('), 'cleanup runtime DML must use DBAL parameter binding');
assertOrderCleanupContract(!str_contains($cleanup, 'Order('), 'cleanup service must not create or mutate Core orders');

assertOrderCleanupContract(str_contains($services, "Checkout\\Finalization\\CheckoutOrderLifecycleCleanup:"), 'cleanup service must be wired in the shared container');
assertOrderCleanupContract(str_contains($services, 'public: true'), 'module hook entry service must be explicitly resolvable');
assertOrderCleanupContract(str_contains($upgrade, "registerHook('actionValidateOrderAfter')"), 'existing installations must receive the cleanup hook in 0.4.0 upgrade');

fwrite(STDOUT, "Checkout post-order cleanup contract smoke tests passed.\n");

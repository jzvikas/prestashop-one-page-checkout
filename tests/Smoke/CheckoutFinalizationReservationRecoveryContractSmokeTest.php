<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$store = file_get_contents($root . '/src/Infrastructure/Persistence/DbalCheckoutFinalizationReservationStore.php');
$services = file_get_contents($root . '/config/common/services.yml');
$module = file_get_contents($root . '/jzonepagecheckout.php');

function assertFinalizationReservationRecoveryContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

assertFinalizationReservationRecoveryContract(is_string($store), 'finalization reservation store source must be readable');
assertFinalizationReservationRecoveryContract(is_string($services), 'service container configuration must be readable');
assertFinalizationReservationRecoveryContract(is_string($module), 'module source must be readable');

assertFinalizationReservationRecoveryContract(
    str_contains($store, 'private int $ttlSeconds = 900'),
    'duplicate-handoff reservation must remain active for a payment-safe default window'
);
assertFinalizationReservationRecoveryContract(
    str_contains($services, '$ttlSeconds: 900'),
    'installed service wiring must not override the payment-safe 900-second reservation window'
);
assertFinalizationReservationRecoveryContract(
    !str_contains($services, '$ttlSeconds: 90'),
    'stale 90-second service-container override must not reopen duplicate handoff too early'
);
assertFinalizationReservationRecoveryContract(
    str_contains($store, '$this->ttlSeconds < 60 || $this->ttlSeconds > 3600'),
    'reservation TTL customization must remain bounded'
);
assertFinalizationReservationRecoveryContract(
    str_contains($store, 'UNIX_TIMESTAMP() + ?'),
    'reservation expiry must remain server/database-time based'
);
assertFinalizationReservationRecoveryContract(
    str_contains($store, 'id_customer = ? AND attempt_id = ?'),
    'browser recovery release must remain customer and attempt scoped'
);
assertFinalizationReservationRecoveryContract(
    str_contains($store, '$this->orderExistsForCart($cartId)'),
    'attempt release must refuse to remove the barrier after Core order creation'
);
assertFinalizationReservationRecoveryContract(
    str_contains($store, '\\Order::getIdByCartId($cartId)'),
    'release safety must consult Core order-by-cart state'
);
assertFinalizationReservationRecoveryContract(
    str_contains($store, 'catch (Throwable)') && str_contains($store, 'return true;'),
    'unknown Core order state must fail closed and preserve the reservation'
);
assertFinalizationReservationRecoveryContract(
    str_contains($store, 'EXPIRED_PURGE_LIMIT = 100'),
    'expired reservation cleanup must stay bounded'
);
assertFinalizationReservationRecoveryContract(
    str_contains($module, 'private const INTEGRATION_SHELL_READY = false;'),
    'reservation hardening must not open the production checkout readiness gate'
);

fwrite(STDOUT, "Checkout finalization reservation recovery contract smoke tests passed.\n");

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
assertFinalizationReservationRecoveryContract(is_string($services), 'finalization reservation service configuration must be readable');
assertFinalizationReservationRecoveryContract(is_string($module), 'module source must be readable');

assertFinalizationReservationRecoveryContract(
    str_contains($store, 'private int $ttlSeconds = 900'),
    'duplicate-handoff reservation must remain active for a payment-safe default window'
);
assertFinalizationReservationRecoveryContract(
    preg_match('/(?m)^\s+\$ttlSeconds:\s+900\s*$/', $services) === 1,
    'front service wiring must preserve the 15-minute duplicate-handoff reservation window'
);
assertFinalizationReservationRecoveryContract(
    preg_match('/(?m)^\s+\$ttlSeconds:\s+90\s*$/', $services) !== 1,
    'front service wiring must not regress to the unsafe 90-second reservation window'
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
    !str_contains($store, 'id_customer <> ?'),
    'a stale or mismatched customer request must never delete an unexpired cart reservation'
);
assertFinalizationReservationRecoveryContract(
    str_contains($store, 'private function activeReservation(int $shopId, int $cartId): ?array'),
    'active reservation lookup must treat the shop/cart row as the cross-tab handoff barrier before customer comparison'
);
assertFinalizationReservationRecoveryContract(
    substr_count($store, "(int) (\$existing['id_customer'] ?? -1) === \$customerId") >= 2,
    'same-attempt idempotency must also require the reservation customer to match in normal and insert-race paths'
);
assertFinalizationReservationRecoveryContract(
    !str_contains($store, "(int) (\$row['id_customer'] ?? -1) !== \$customerId"),
    'active reservation lookup must not clear the barrier merely because current cart customer identity differs'
);
assertFinalizationReservationRecoveryContract(
    str_contains($store, 'reservation.id_customer = ? AND reservation.attempt_id = ?'),
    'browser recovery release must remain customer and attempt scoped'
);
assertFinalizationReservationRecoveryContract(
    str_contains($store, 'AND NOT EXISTS (SELECT 1 FROM `%2$s` orders WHERE orders.id_cart = ?)'),
    'attempt release and Core-order absence must be evaluated in the same SQL statement'
);
assertFinalizationReservationRecoveryContract(
    !str_contains($store, '\\Order::getIdByCartId($cartId)'),
    'release safety must not regress to a separate Core order lookup with a TOCTOU window'
);
assertFinalizationReservationRecoveryContract(
    str_contains($store, "private function ordersTableName(): string"),
    'atomic release must resolve the prefixed Core orders table through validated table naming'
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

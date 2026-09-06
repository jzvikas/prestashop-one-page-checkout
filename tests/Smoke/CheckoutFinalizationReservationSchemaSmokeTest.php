<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$schema = file_get_contents($root . '/src/Infrastructure/Persistence/CheckoutFinalizationReservationSchema.php');
$store = file_get_contents($root . '/src/Infrastructure/Persistence/DbalCheckoutFinalizationReservationStore.php');
$services = file_get_contents($root . '/config/common/services.yml');
$module = file_get_contents($root . '/jzonepagecheckout.php');

function assertFinalizationReservationContract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

foreach ([$schema, $store, $services, $module] as $source) {
    assertFinalizationReservationContract(is_string($source) && $source !== '', 'finalization reservation source must be readable');
}

assertFinalizationReservationContract(str_contains($schema, "jzopc_checkout_finalization"), 'dedicated finalization table is required');
assertFinalizationReservationContract(str_contains($schema, 'PRIMARY KEY (`id_shop`, `id_cart`)'), 'one reservation row per shop/cart is required');
assertFinalizationReservationContract(str_contains($schema, '`id_customer` INT UNSIGNED NOT NULL'), 'reservation must bind current customer');
assertFinalizationReservationContract(str_contains($schema, '`state_version` VARCHAR(128) NOT NULL'), 'reservation must bind authoritative state version');
assertFinalizationReservationContract(str_contains($schema, '`selected_payment_option` VARCHAR(255) NOT NULL'), 'reservation must bind canonical payment selection');
assertFinalizationReservationContract(str_contains($schema, '`expires_at` BIGINT UNSIGNED NOT NULL'), 'reservation must expire');

assertFinalizationReservationContract(str_contains($store, 'UNIX_TIMESTAMP()'), 'reservation expiry must use database time');
assertFinalizationReservationContract(str_contains($store, 'CheckoutFinalizationReservationAlreadyActive'), 'concurrent reservation must fail closed');
assertFinalizationReservationContract(
    str_contains($store, "(int) (\$existing['id_customer'] ?? -1) === \$customerId")
        && !str_contains($store, 'id_customer <> ?'),
    'an active reservation must remain a cart-level barrier across customer-binding changes',
);
assertFinalizationReservationContract(str_contains($store, 'INSERT INTO `%s`'), 'reservation acquisition must be a database write');
assertFinalizationReservationContract(!str_contains($store, 'ON DUPLICATE KEY UPDATE'), 'active reservation must never be silently overwritten');
assertFinalizationReservationContract(
    str_contains($store, 'AND reservation.id_customer = ? AND reservation.attempt_id = ?'),
    'explicit release must remain exact customer/attempt scoped',
);
assertFinalizationReservationContract(
    str_contains($store, 'AND NOT EXISTS (SELECT 1 FROM `%2$s` orders WHERE orders.id_cart = ?)'),
    'explicit release must atomically preserve the barrier when Core already has an order',
);
assertFinalizationReservationContract(
    str_contains($store, "DELETE FROM `%s` WHERE id_shop = ? AND id_cart = ? AND expires_at <= UNIX_TIMESTAMP()"),
    'expired-row cleanup must recheck database-time expiry before deleting a cart barrier',
);

assertFinalizationReservationContract(
    str_contains($services, 'CheckoutFinalizationReservationStoreInterface:'),
    'reservation store interface must be wired in the front container',
);
assertFinalizationReservationContract(
    preg_match('/(?m)^\s+\$ttlSeconds:\s+900\s*$/', $services) === 1,
    'reservation TTL must be explicitly wired to the 15-minute safety window',
);
assertFinalizationReservationContract(
    preg_match('/(?m)^\s+\$ttlSeconds:\s+90\s*$/', $services) !== 1,
    'reservation TTL must not regress to the obsolete 90-second override',
);
assertFinalizationReservationContract(str_contains($module, "$" . "this->version = '0.4.0';"), 'schema change must retain the 0.4.0 schema baseline');
assertFinalizationReservationContract(str_contains($module, 'CheckoutFinalizationReservationSchema'), 'fresh install/uninstall must manage reservation schema');

echo "CheckoutFinalizationReservationSchemaSmokeTest OK\n";

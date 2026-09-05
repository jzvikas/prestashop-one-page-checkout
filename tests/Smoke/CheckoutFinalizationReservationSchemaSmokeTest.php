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
assertFinalizationReservationContract(str_contains($store, 'id_customer <> ?'), 'stale customer reservation must not remain authoritative');
assertFinalizationReservationContract(str_contains($store, 'INSERT INTO `%s`'), 'reservation acquisition must be a database write');
assertFinalizationReservationContract(!str_contains($store, 'ON DUPLICATE KEY UPDATE'), 'active reservation must never be silently overwritten');
assertFinalizationReservationContract(str_contains($store, 'DELETE FROM `%s` WHERE id_shop = ? AND id_cart = ?'), 'reservation cleanup must remain cart-scoped');

assertFinalizationReservationContract(
    str_contains($services, 'CheckoutFinalizationReservationStoreInterface:'),
    'reservation store interface must be wired in the front container',
);
assertFinalizationReservationContract(str_contains($services, '$ttlSeconds: 90'), 'reservation TTL must be explicit in DI');
assertFinalizationReservationContract(str_contains($module, "$" . "this->version = '0.4.0';"), 'schema change must bump module version');
assertFinalizationReservationContract(str_contains($module, 'CheckoutFinalizationReservationSchema'), 'fresh install/uninstall must manage reservation schema');

echo "CheckoutFinalizationReservationSchemaSmokeTest OK\n";

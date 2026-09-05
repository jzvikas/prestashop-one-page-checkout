<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Persistence/DbalCheckoutServerSelectionsStore.php');
assert(is_string($source));

assert(str_contains($source, 'private const ABANDONED_RETENTION_DAYS = 30;'));
assert(str_contains($source, 'private const ABANDONED_PURGE_LIMIT = 100;'));
assert(str_contains($source, 'private const ABANDONED_PURGE_CHANCE_DENOMINATOR = 64;'));
assert(str_contains($source, '$this->maybePurgeAbandoned();'));
assert(str_contains($source, "WHERE date_upd < DATE_SUB(NOW(), INTERVAL %d DAY) LIMIT %d"));
assert(str_contains($source, 'mt_rand(1, self::ABANDONED_PURGE_CHANCE_DENOMINATOR)'));
assert(strpos($source, '$this->maybePurgeAbandoned();') < strpos($source, "'INSERT INTO `%1$s`"));

// GC must remain transient-state-only. It must not inspect/delete Core carts or orders and must
// not turn old browser selections into authority.
assert(!str_contains($source, 'DELETE FROM `ps_cart'));
assert(!str_contains($source, 'DELETE FROM `ps_orders'));
assert(!str_contains($source, 'validateOrder('));

echo "CheckoutSelectionGarbageCollectionContractSmokeTest OK\n";

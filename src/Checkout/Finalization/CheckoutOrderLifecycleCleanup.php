<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout\Finalization;

use Doctrine\DBAL\Connection;
use RuntimeException;

final readonly class CheckoutOrderLifecycleCleanup
{
    public function __construct(private Connection $connection)
    {
    }

    public function cleanupForCart(\Cart $cart): void
    {
        $shopId = (int) ($cart->id_shop ?? 0);
        $cartId = (int) ($cart->id ?? 0);
        if ($shopId <= 0 || $cartId <= 0) {
            throw new RuntimeException('Checkout order cleanup requires a valid cart identity.');
        }

        $prefix = defined('_DB_PREFIX_') ? (string) constant('_DB_PREFIX_') : '';
        if (preg_match('/\A[A-Za-z0-9_]*\z/D', $prefix) !== 1) {
            throw new RuntimeException('Invalid database prefix for checkout order cleanup.');
        }

        // The Core/payment module owns the order. We only remove module-owned transient checkout
        // state after Core has already created an order for this cart.
        $this->connection->executeStatement(
            sprintf('DELETE FROM `%sjzopc_checkout_finalization` WHERE id_shop = ? AND id_cart = ?', $prefix),
            [$shopId, $cartId],
        );
        $this->connection->executeStatement(
            sprintf('DELETE FROM `%sjzopc_checkout_selection` WHERE id_shop = ? AND id_cart = ?', $prefix),
            [$shopId, $cartId],
        );
    }
}

<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Jzvikas\OnePageCheckout\Checkout\Finalization\CheckoutFinalizationReservationAlreadyActive;
use Jzvikas\OnePageCheckout\Checkout\Finalization\CheckoutFinalizationReservationStoreInterface;
use RuntimeException;
use Throwable;

final readonly class DbalCheckoutFinalizationReservationStore implements CheckoutFinalizationReservationStoreInterface
{
    public function __construct(
        private Connection $connection,
        private int $ttlSeconds = 90,
    ) {
        if ($this->ttlSeconds < 10 || $this->ttlSeconds > 600) {
            throw new RuntimeException('Checkout finalization reservation TTL must be between 10 and 600 seconds.');
        }
    }

    public function acquire(
        \Context $context,
        string $stateVersion,
        string $paymentSelection,
        string $attemptId,
    ): void {
        $stateVersion = trim($stateVersion);
        $paymentSelection = trim($paymentSelection);
        $attemptId = strtolower(trim($attemptId));
        if ($stateVersion === '' || strlen($stateVersion) > 128) {
            throw new RuntimeException('Checkout finalization state version is invalid.');
        }
        if ($paymentSelection === '' || strlen($paymentSelection) > 255) {
            throw new RuntimeException('Checkout finalization payment selection is invalid.');
        }
        if (preg_match('/\A[a-f0-9]{32}\z/D', $attemptId) !== 1) {
            throw new RuntimeException('Checkout finalization attempt identifier is invalid.');
        }

        [$shopId, $cartId, $customerId] = $this->identity($context);
        if ($customerId <= 0) {
            throw new RuntimeException('Checkout finalization requires a cart-bound customer.');
        }

        $this->connection->executeStatement(
            sprintf(
                'DELETE FROM `%s` WHERE id_shop = ? AND id_cart = ? AND (expires_at <= UNIX_TIMESTAMP() OR id_customer <> ?)',
                $this->tableName(),
            ),
            [$shopId, $cartId, $customerId],
        );

        $existing = $this->activeReservation($shopId, $cartId, $customerId);
        if ($existing !== null) {
            if ($this->matchesAttempt($existing, $stateVersion, $paymentSelection, $attemptId)) {
                return;
            }

            throw new CheckoutFinalizationReservationAlreadyActive('Checkout finalization is already reserved for this cart.');
        }

        try {
            $this->connection->executeStatement(
                sprintf(
                    'INSERT INTO `%s` (id_shop, id_cart, id_customer, state_version, selected_payment_option, attempt_id, expires_at, date_add) '
                    . 'VALUES (?, ?, ?, ?, ?, ?, UNIX_TIMESTAMP() + ?, NOW())',
                    $this->tableName(),
                ),
                [$shopId, $cartId, $customerId, $stateVersion, $paymentSelection, $attemptId, $this->ttlSeconds],
            );
        } catch (Throwable $exception) {
            $existing = $this->activeReservation($shopId, $cartId, $customerId);
            if ($existing !== null && $this->matchesAttempt($existing, $stateVersion, $paymentSelection, $attemptId)) {
                return;
            }
            if ($existing !== null) {
                throw new CheckoutFinalizationReservationAlreadyActive(
                    'Checkout finalization is already reserved for this cart.',
                    0,
                    $exception,
                );
            }

            throw $exception;
        }
    }

    public function isActive(\Context $context): bool
    {
        [$shopId, $cartId, $customerId] = $this->identity($context);

        return $this->activeReservation($shopId, $cartId, $customerId) !== null;
    }

    public function clear(\Context $context): void
    {
        [$shopId, $cartId] = $this->identity($context);
        $this->deleteByIdentity($shopId, $cartId);
    }

    /** @return array<string,mixed>|null */
    private function activeReservation(int $shopId, int $cartId, int $customerId): ?array
    {
        $row = $this->connection
            ->executeQuery(
                sprintf(
                    'SELECT id_customer, state_version, selected_payment_option, attempt_id, '
                    . '(expires_at > UNIX_TIMESTAMP()) AS is_active FROM `%s` '
                    . 'WHERE id_shop = ? AND id_cart = ? LIMIT 1',
                    $this->tableName(),
                ),
                [$shopId, $cartId],
            )
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        if ((int) ($row['id_customer'] ?? -1) !== $customerId || (int) ($row['is_active'] ?? 0) !== 1) {
            $this->deleteByIdentity($shopId, $cartId);

            return null;
        }

        return $row;
    }

    /** @param array<string,mixed> $row */
    private function matchesAttempt(
        array $row,
        string $stateVersion,
        string $paymentSelection,
        string $attemptId,
    ): bool {
        $storedState = $row['state_version'] ?? null;
        $storedPayment = $row['selected_payment_option'] ?? null;
        $storedAttempt = $row['attempt_id'] ?? null;

        return is_string($storedState)
            && is_string($storedPayment)
            && is_string($storedAttempt)
            && hash_equals($storedState, $stateVersion)
            && hash_equals($storedPayment, $paymentSelection)
            && hash_equals(strtolower($storedAttempt), $attemptId);
    }

    /** @return array{0:int,1:int,2:int} */
    private function identity(\Context $context): array
    {
        $cart = $context->cart ?? null;
        if (!$cart instanceof \Cart) {
            throw new RuntimeException('Checkout finalization storage requires a loaded cart.');
        }

        $shopId = (int) ($cart->id_shop ?? 0);
        $cartId = (int) ($cart->id ?? 0);
        $customerId = (int) ($cart->id_customer ?? 0);
        if ($shopId <= 0 || $cartId <= 0 || $customerId < 0) {
            throw new RuntimeException('Checkout finalization storage requires valid cart identity.');
        }

        return [$shopId, $cartId, $customerId];
    }

    private function deleteByIdentity(int $shopId, int $cartId): void
    {
        $this->connection->executeStatement(
            sprintf('DELETE FROM `%s` WHERE id_shop = ? AND id_cart = ?', $this->tableName()),
            [$shopId, $cartId],
        );
    }

    private function tableName(): string
    {
        $prefix = defined('_DB_PREFIX_') ? (string) constant('_DB_PREFIX_') : '';
        if (preg_match('/\A[A-Za-z0-9_]*\z/D', $prefix) !== 1) {
            throw new RuntimeException('Invalid database prefix for checkout finalization storage.');
        }

        return $prefix . 'jzopc_checkout_finalization';
    }
}

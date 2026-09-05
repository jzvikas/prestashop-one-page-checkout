<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use JsonException;
use Jzvikas\OnePageCheckout\Checkout\CheckoutServerSelections;
use Jzvikas\OnePageCheckout\Checkout\CheckoutServerSelectionsStoreInterface;
use RuntimeException;

final readonly class DbalCheckoutServerSelectionsStore implements CheckoutServerSelectionsStoreInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function load(\Context $context): CheckoutServerSelections
    {
        [$shopId, $cartId, $customerId] = $this->identity($context);

        $row = $this->connection
            ->executeQuery(
                sprintf(
                    'SELECT id_customer, selected_payment_option, approved_agreements FROM `%s` WHERE id_shop = ? AND id_cart = ? LIMIT 1',
                    $this->tableName(),
                ),
                [$shopId, $cartId],
            )
            ->fetchAssociative();

        if ($row === false) {
            return new CheckoutServerSelections();
        }

        if ((int) ($row['id_customer'] ?? -1) !== $customerId) {
            $this->delete($context);

            return new CheckoutServerSelections();
        }

        try {
            $agreements = json_decode((string) ($row['approved_agreements'] ?? '[]'), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Stored checkout agreement state is invalid.', 0, $exception);
        }

        if (!is_array($agreements)) {
            throw new RuntimeException('Stored checkout agreement state is invalid.');
        }

        $paymentOption = $row['selected_payment_option'] ?? null;
        if ($paymentOption !== null && !is_string($paymentOption)) {
            throw new RuntimeException('Stored checkout payment state is invalid.');
        }

        return new CheckoutServerSelections($paymentOption, $agreements);
    }

    public function save(\Context $context, CheckoutServerSelections $selections): void
    {
        [$shopId, $cartId, $customerId] = $this->identity($context);

        try {
            $agreementsJson = json_encode(
                $selections->approvedAgreementKeys,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('Checkout agreement state could not be encoded.', 0, $exception);
        }

        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO `%1$s` (id_shop, id_cart, id_customer, selected_payment_option, approved_agreements, date_upd) '
                . 'VALUES (?, ?, ?, ?, ?, NOW()) '
                . 'ON DUPLICATE KEY UPDATE id_customer = VALUES(id_customer), selected_payment_option = VALUES(selected_payment_option), '
                . 'approved_agreements = VALUES(approved_agreements), date_upd = NOW()',
                $this->tableName(),
            ),
            [$shopId, $cartId, $customerId, $selections->selectedPaymentOption, $agreementsJson],
        );
    }

    public function delete(\Context $context): void
    {
        [$shopId, $cartId] = $this->identity($context);

        $this->connection->executeStatement(
            sprintf('DELETE FROM `%s` WHERE id_shop = ? AND id_cart = ?', $this->tableName()),
            [$shopId, $cartId],
        );
    }

    /** @return array{0:int,1:int,2:int} */
    private function identity(\Context $context): array
    {
        $cart = $context->cart ?? null;
        if (!$cart instanceof \Cart) {
            throw new RuntimeException('Checkout selection storage requires a loaded cart.');
        }

        $cartId = (int) ($cart->id ?? 0);
        $shopId = (int) ($cart->id_shop ?? 0);
        $customerId = (int) ($cart->id_customer ?? 0);
        if ($cartId <= 0 || $shopId <= 0 || $customerId < 0) {
            throw new RuntimeException('Checkout selection storage requires valid cart identity.');
        }

        return [$shopId, $cartId, $customerId];
    }

    private function tableName(): string
    {
        $prefix = defined('_DB_PREFIX_') ? (string) constant('_DB_PREFIX_') : '';
        if (preg_match('/\A[A-Za-z0-9_]*\z/D', $prefix) !== 1) {
            throw new RuntimeException('Invalid database prefix for checkout selection storage.');
        }

        return $prefix . 'jzopc_checkout_selection';
    }
}

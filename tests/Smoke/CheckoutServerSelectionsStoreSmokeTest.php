<?php

declare(strict_types=1);

namespace Doctrine\DBAL {
    final class Result
    {
        public function __construct(private array|false $row) {}

        public function fetchAssociative(): array|false
        {
            return $this->row;
        }
    }

    class Connection
    {
        /** @var array<string,mixed>|null */
        public ?array $row = null;
        /** @var list<array{sql:string,params:array}> */
        public array $statements = [];

        public function executeQuery(string $sql, array $params = []): Result
        {
            $this->statements[] = ['sql' => $sql, 'params' => $params];

            return new Result($this->row ?? false);
        }

        public function executeStatement(string $sql, array $params = []): int
        {
            $this->statements[] = ['sql' => $sql, 'params' => $params];
            if (str_starts_with($sql, 'INSERT INTO')) {
                $this->row = [
                    'id_customer' => $params[2],
                    'selected_payment_option' => $params[3],
                    'approved_agreements' => $params[4],
                ];
            } elseif (str_starts_with($sql, 'DELETE FROM')) {
                $this->row = null;
            }

            return 1;
        }
    }
}

namespace {
    class Cart
    {
        public int $id = 42;
        public int $id_shop = 2;
        public int $id_customer = 9;
    }

    class Context
    {
        public function __construct(public Cart $cart) {}
    }

    if (!defined('_DB_PREFIX_')) {
        define('_DB_PREFIX_', 'ps_');
    }

    require dirname(__DIR__) . '/bootstrap.php';

    use Doctrine\DBAL\Connection;
    use Jzvikas\OnePageCheckout\Checkout\CheckoutServerSelections;
    use Jzvikas\OnePageCheckout\Infrastructure\Persistence\DbalCheckoutServerSelectionsStore;

    $connection = new Connection();
    $store = new DbalCheckoutServerSelectionsStore($connection);
    $context = new Context(new Cart());

    $empty = $store->load($context);
    assert($empty->selectedPaymentOption === null);
    assert($empty->approvedAgreementKeys === []);

    $store->save($context, new CheckoutServerSelections('demo:payment-option-1', ['terms', 'privacy']));
    $insert = $connection->statements[array_key_last($connection->statements)];
    assert(str_contains($insert['sql'], 'INSERT INTO `ps_jzopc_checkout_selection`'));
    assert($insert['params'][0] === 2);
    assert($insert['params'][1] === 42);
    assert($insert['params'][2] === 9);
    assert($insert['params'][3] === 'demo:payment-option-1');
    assert(json_decode($insert['params'][4], true, 64, JSON_THROW_ON_ERROR) === ['privacy', 'terms']);

    $loaded = $store->load($context);
    assert($loaded->selectedPaymentOption === 'demo:payment-option-1');
    assert($loaded->approvedAgreementKeys === ['privacy', 'terms']);

    $context->cart->id_customer = 10;
    $mismatch = $store->load($context);
    assert($mismatch->selectedPaymentOption === null);
    assert($mismatch->approvedAgreementKeys === []);
    assert($connection->row === null);

    echo "CheckoutServerSelectionsStoreSmokeTest OK\n";
}

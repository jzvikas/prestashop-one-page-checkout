<?php

declare(strict_types=1);

final class Db
{
    public static ?self $instance = null;
    /** @var list<string> */
    public array $queries = [];

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public function execute(string $sql): bool
    {
        $this->queries[] = $sql;

        return true;
    }
}

if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}
if (!defined('_MYSQL_ENGINE_')) {
    define('_MYSQL_ENGINE_', 'InnoDB');
}

require dirname(__DIR__) . '/bootstrap.php';

use Jzvikas\OnePageCheckout\Infrastructure\Persistence\CheckoutServerSelectionsSchema;

$schema = new CheckoutServerSelectionsSchema();
assert($schema->install());
$installSql = Db::getInstance()->queries[0] ?? '';
assert(str_contains($installSql, 'CREATE TABLE IF NOT EXISTS `ps_jzopc_checkout_selection`'));
assert(str_contains($installSql, 'PRIMARY KEY (`id_shop`, `id_cart`)'));
assert(str_contains($installSql, '`approved_agreements` TEXT NOT NULL'));
assert(str_contains($installSql, 'ENGINE=InnoDB'));

assert($schema->uninstall());
$uninstallSql = Db::getInstance()->queries[1] ?? '';
assert($uninstallSql === 'DROP TABLE IF EXISTS `ps_jzopc_checkout_selection`');

echo "CheckoutServerSelectionsSchemaSmokeTest OK\n";

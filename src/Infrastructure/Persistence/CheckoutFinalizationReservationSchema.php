<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Infrastructure\Persistence;

use RuntimeException;

final class CheckoutFinalizationReservationSchema
{
    public function install(): bool
    {
        $table = $this->tableName();
        $engine = defined('_MYSQL_ENGINE_') ? (string) constant('_MYSQL_ENGINE_') : 'InnoDB';
        if (preg_match('/\A[A-Za-z0-9_]+\z/D', $engine) !== 1) {
            throw new RuntimeException('Invalid database engine for checkout finalization storage.');
        }

        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` ('
            . '`id_shop` INT UNSIGNED NOT NULL,'
            . '`id_cart` INT UNSIGNED NOT NULL,'
            . '`id_customer` INT UNSIGNED NOT NULL DEFAULT 0,'
            . '`state_version` VARCHAR(128) NOT NULL,'
            . '`selected_payment_option` VARCHAR(255) NOT NULL,'
            . '`attempt_id` CHAR(32) NOT NULL,'
            . '`expires_at` BIGINT UNSIGNED NOT NULL,'
            . '`date_add` DATETIME NOT NULL,'
            . 'PRIMARY KEY (`id_shop`, `id_cart`),'
            . 'KEY `idx_jzopc_checkout_finalization_expires_at` (`expires_at`)'
            . ') ENGINE=%s DEFAULT CHARSET=utf8mb4',
            $table,
            $engine,
        );

        return (bool) \Db::getInstance()->execute($sql);
    }

    public function uninstall(): bool
    {
        return (bool) \Db::getInstance()->execute(sprintf(
            'DROP TABLE IF EXISTS `%s`',
            $this->tableName(),
        ));
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

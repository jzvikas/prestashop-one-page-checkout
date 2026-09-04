<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Concurrency;

use Closure;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Throwable;

final readonly class CheckoutCartMutex
{
    private const MAX_TIMEOUT_SECONDS = 30;

    public function __construct(
        private Connection $connection,
        private int $timeoutSeconds = 5,
    ) {
        if ($timeoutSeconds < 0 || $timeoutSeconds > self::MAX_TIMEOUT_SECONDS) {
            throw new InvalidArgumentException(sprintf(
                'Checkout cart lock timeout must be between 0 and %d seconds.',
                self::MAX_TIMEOUT_SECONDS,
            ));
        }
    }

    public function synchronized(int $cartId, Closure $criticalSection): mixed
    {
        if ($cartId <= 0) {
            throw new InvalidArgumentException('Checkout cart lock requires a positive cart id.');
        }

        $lockName = $this->lockName($cartId);

        try {
            $acquired = (int) $this->connection
                ->executeQuery(
                    'SELECT GET_LOCK(?, ?)',
                    [$lockName, $this->timeoutSeconds],
                )
                ->fetchOne() === 1;
        } catch (Throwable $exception) {
            throw new CheckoutCartLockUnavailable($cartId, $exception);
        }

        if (!$acquired) {
            throw new CheckoutCartLockUnavailable($cartId);
        }

        try {
            return $criticalSection();
        } finally {
            $this->release($lockName);
        }
    }

    private function lockName(int $cartId): string
    {
        $databaseName = defined('_DB_NAME_') ? (string) constant('_DB_NAME_') : '';
        $databasePrefix = defined('_DB_PREFIX_') ? (string) constant('_DB_PREFIX_') : '';
        $installationScope = substr(hash('sha256', $databaseName . "\0" . $databasePrefix), 0, 16);

        return sprintf('jzopc_%s_cart_%d', $installationScope, $cartId);
    }

    private function release(string $lockName): void
    {
        try {
            $released = (int) $this->connection
                ->executeQuery('SELECT RELEASE_LOCK(?)', [$lockName])
                ->fetchOne() === 1;

            if ($released) {
                return;
            }
        } catch (Throwable) {
            // Closing the DBAL connection below releases connection-owned MySQL/MariaDB locks.
        }

        try {
            $this->connection->close();
        } catch (Throwable) {
            // The PHP/request lifecycle is the final release boundary if close itself fails.
        }
    }
}

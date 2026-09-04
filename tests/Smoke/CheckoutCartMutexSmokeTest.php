<?php

declare(strict_types=1);

namespace Doctrine\DBAL {
    final class Result
    {
        public function __construct(private mixed $value) {}

        public function fetchOne(): mixed
        {
            return $this->value;
        }
    }

    class Connection
    {
        /** @var list<array{sql:string,params:array<int,mixed>}> */
        public array $queries = [];

        /** @var list<mixed> */
        public array $responses = [];

        public int $closeCalls = 0;
        public ?\Throwable $nextException = null;

        public function executeQuery(string $sql, array $params = []): Result
        {
            $this->queries[] = ['sql' => $sql, 'params' => $params];

            if ($this->nextException !== null) {
                $exception = $this->nextException;
                $this->nextException = null;
                throw $exception;
            }

            return new Result(array_shift($this->responses));
        }

        public function close(): void
        {
            ++$this->closeCalls;
        }
    }
}

namespace {
    require dirname(__DIR__) . '/bootstrap.php';

    use Doctrine\DBAL\Connection;
    use Jzvikas\OnePageCheckout\Concurrency\CheckoutCartLockUnavailable;
    use Jzvikas\OnePageCheckout\Concurrency\CheckoutCartMutex;

    function assertMutex(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    $connection = new Connection();
    $connection->responses = [1, 1];
    $mutex = new CheckoutCartMutex($connection, 3);
    $ran = false;
    $value = $mutex->synchronized(42, static function () use (&$ran): string {
        $ran = true;

        return 'done';
    });

    assertMutex($ran && $value === 'done', 'critical section must run after lock acquisition');
    assertMutex(count($connection->queries) === 2, 'successful lock must acquire and release exactly once');
    assertMutex($connection->queries[0]['sql'] === 'SELECT GET_LOCK(?, ?)', 'acquisition SQL must be parameterized');
    assertMutex($connection->queries[0]['params'][1] === 3, 'configured lock timeout must be bound as a parameter');
    assertMutex($connection->queries[1]['sql'] === 'SELECT RELEASE_LOCK(?)', 'release SQL must be parameterized');
    assertMutex($connection->queries[0]['params'][0] === $connection->queries[1]['params'][0], 'release must use acquired lock name');

    $busyConnection = new Connection();
    $busyConnection->responses = [0];
    $busyMutex = new CheckoutCartMutex($busyConnection);
    $busyRan = false;
    try {
        $busyMutex->synchronized(42, static function () use (&$busyRan): void {
            $busyRan = true;
        });
        assertMutex(false, 'busy lock must throw');
    } catch (CheckoutCartLockUnavailable $exception) {
        assertMutex($exception->cartId === 42, 'busy lock exception must retain non-sensitive cart id context');
    }
    assertMutex(!$busyRan, 'critical section must never run when lock is unavailable');

    $failingAcquireConnection = new Connection();
    $failingAcquireConnection->nextException = new \RuntimeException('db unavailable');
    try {
        (new CheckoutCartMutex($failingAcquireConnection))->synchronized(42, static fn (): null => null);
        assertMutex(false, 'acquisition error must fail closed');
    } catch (CheckoutCartLockUnavailable $exception) {
        assertMutex($exception->getPrevious() instanceof \RuntimeException, 'acquisition error must be chained for server logging');
    }

    $exceptionConnection = new Connection();
    $exceptionConnection->responses = [1, 1];
    try {
        (new CheckoutCartMutex($exceptionConnection))->synchronized(42, static function (): never {
            throw new \LogicException('mutation failed');
        });
        assertMutex(false, 'critical-section exception must propagate');
    } catch (\LogicException $exception) {
        assertMutex($exception->getMessage() === 'mutation failed', 'original mutation exception must be preserved');
    }
    assertMutex(count($exceptionConnection->queries) === 2, 'lock must release in finally after mutation exception');

    $releaseFailureConnection = new Connection();
    $releaseFailureConnection->responses = [1, 0];
    $releaseMutex = new CheckoutCartMutex($releaseFailureConnection);
    $releaseMutex->synchronized(42, static fn (): bool => true);
    assertMutex($releaseFailureConnection->closeCalls === 1, 'failed RELEASE_LOCK must close DBAL connection as final release attempt');

    fwrite(STDOUT, "Checkout cart mutex smoke tests passed.\n");
}

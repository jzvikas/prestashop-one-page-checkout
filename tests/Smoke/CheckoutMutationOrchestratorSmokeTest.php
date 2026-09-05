<?php

declare(strict_types=1);

final class Tools
{
    public static string $token = 'csrf-token';

    public static function getToken(bool $page = true): string
    {
        return self::$token;
    }
}

final class AddressChecksum {}

final class CartChecksum
{
    public function __construct(AddressChecksum $addressChecksum) {}

    public function generateChecksum(Cart $cart): string
    {
        return $cart->coreChecksum;
    }
}

final class OrchestratorLockState
{
    public static bool $held = false;
    public static int $stateReadsOutsideLock = 0;
}

class Cart
{
    public const BOTH = 0;
    public const ONLY_PRODUCTS = 1;
    public const ONLY_DISCOUNTS = 2;
    public const ONLY_SHIPPING = 3;
    public const ONLY_WRAPPING = 4;

    public int $id = 42;
    public int $id_shop = 2;
    public int $id_customer = 9;
    public int $id_lang = 1;
    public int $id_currency = 3;
    public int $id_address_delivery = 11;
    public int $id_address_invoice = 12;
    public int $id_carrier = 7;
    public string $delivery_option = '7,';
    public bool $recyclable = false;
    public bool $gift = false;
    public string $gift_message = '';
    public string $coreChecksum = 'core-a';

    public function getCartRules(): array
    {
        if (!OrchestratorLockState::$held) {
            ++OrchestratorLockState::$stateReadsOutsideLock;
        }

        return [];
    }

    public function isVirtualCart(): bool
    {
        return false;
    }

    public function getOrderTotal(bool $withTaxes, int $type): float
    {
        if (!OrchestratorLockState::$held) {
            ++OrchestratorLockState::$stateReadsOutsideLock;
        }

        return match ([$withTaxes, $type]) {
            [true, self::BOTH] => 120.0,
            [false, self::BOTH] => 100.0,
            [true, self::ONLY_PRODUCTS] => 110.0,
            [true, self::ONLY_DISCOUNTS] => 0.0,
            [true, self::ONLY_SHIPPING] => 10.0,
            [true, self::ONLY_WRAPPING] => 0.0,
            default => 0.0,
        };
    }
}

final class Customer
{
    public function __construct(public int $id) {}
}

final class Context
{
    public function __construct(public ?Cart $cart, public ?Customer $customer) {}
}

require dirname(__DIR__) . '/bootstrap.php';

use Closure;
use Jzvikas\OnePageCheckout\Checkout\CheckoutError;
use Jzvikas\OnePageCheckout\Checkout\CheckoutMutation;
use Jzvikas\OnePageCheckout\Checkout\CheckoutMutationExecutionStatus;
use Jzvikas\OnePageCheckout\Checkout\CheckoutMutationOrchestrator;
use Jzvikas\OnePageCheckout\Checkout\CheckoutMutationOutcome;
use Jzvikas\OnePageCheckout\Checkout\CheckoutSection;
use Jzvikas\OnePageCheckout\Checkout\CheckoutSectionDependencyResolver;
use Jzvikas\OnePageCheckout\Checkout\CheckoutServerSelections;
use Jzvikas\OnePageCheckout\Checkout\CheckoutServerSelectionsStoreInterface;
use Jzvikas\OnePageCheckout\Checkout\CheckoutStateVersioner;
use Jzvikas\OnePageCheckout\Checkout\PrestaShopCheckoutStateFactory;
use Jzvikas\OnePageCheckout\Checkout\StaleCheckoutStateGuard;
use Jzvikas\OnePageCheckout\Concurrency\CheckoutCartLockUnavailable;
use Jzvikas\OnePageCheckout\Concurrency\CheckoutCartMutexInterface;
use Jzvikas\OnePageCheckout\Security\CheckoutCsrfTokenValidator;
use Jzvikas\OnePageCheckout\Security\CheckoutMutationBlockReason;
use Jzvikas\OnePageCheckout\Security\CheckoutMutationGuard;

final class FakeCheckoutCartMutex implements CheckoutCartMutexInterface
{
    public int $calls = 0;
    public bool $busy = false;

    public function synchronized(int $cartId, Closure $criticalSection): mixed
    {
        ++$this->calls;
        if ($this->busy) {
            throw new CheckoutCartLockUnavailable($cartId);
        }

        OrchestratorLockState::$held = true;
        try {
            return $criticalSection();
        } finally {
            OrchestratorLockState::$held = false;
        }
    }
}

final class FakeCheckoutServerSelectionsStore implements CheckoutServerSelectionsStoreInterface
{
    public CheckoutServerSelections $selections;
    public int $loads = 0;
    public int $saves = 0;

    public function __construct()
    {
        $this->selections = new CheckoutServerSelections();
    }

    public function load(Context $context): CheckoutServerSelections
    {
        assertOrchestrator(OrchestratorLockState::$held, 'server selections must load while cart mutex is held');
        ++$this->loads;

        return $this->selections;
    }

    public function save(Context $context, CheckoutServerSelections $selections): void
    {
        assertOrchestrator(OrchestratorLockState::$held, 'server selections must save while cart mutex is held');
        ++$this->saves;
        $this->selections = $selections;
    }

    public function delete(Context $context): void
    {
        $this->selections = new CheckoutServerSelections();
    }
}

function assertOrchestrator(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function sectionHtml(array $requiredSections): array
{
    $sections = [];
    foreach ($requiredSections as $section) {
        $sections[$section->value] = '<div>' . $section->value . '</div>';
    }

    return $sections;
}

$cart = new Cart();
$context = new Context($cart, new Customer(9));
$mutex = new FakeCheckoutCartMutex();
$store = new FakeCheckoutServerSelectionsStore();
$csrf = new CheckoutCsrfTokenValidator();
$stateFactory = new PrestaShopCheckoutStateFactory();
$versioner = new CheckoutStateVersioner();
$guard = new CheckoutMutationGuard($csrf, $stateFactory, new StaleCheckoutStateGuard($versioner));
$orchestrator = new CheckoutMutationOrchestrator(
    $mutex,
    $csrf,
    $guard,
    new CheckoutSectionDependencyResolver(),
    $stateFactory,
    $versioner,
    $store,
);

OrchestratorLockState::$held = true;
$initialVersion = $versioner->version($stateFactory->create($context, $store->selections));
OrchestratorLockState::$held = false;
OrchestratorLockState::$stateReadsOutsideLock = 0;
$request = ['token' => 'csrf-token', 'cartId' => '42', 'stateVersion' => $initialVersion];

$handlerRan = false;
$result = $orchestrator->execute(
    $context,
    $request,
    CheckoutMutation::CarrierSelected,
    static function ($state, array $requiredSections, CheckoutServerSelections $currentSelections) use (&$handlerRan): CheckoutMutationOutcome {
        assertOrchestrator(OrchestratorLockState::$held, 'mutation handler must execute while cart mutex is held');
        assertOrchestrator($state->cartId === 42, 'handler must receive guarded current server state');
        assertOrchestrator($currentSelections->selectedPaymentOption === null, 'handler must receive server-loaded selections');
        assertOrchestrator(
            array_map(static fn (CheckoutSection $section): string => $section->value, $requiredSections)
                === ['delivery', 'payment', 'agreements', 'summary'],
            'handler must receive dependency-resolved carrier/payment/legal/totals sections'
        );
        $handlerRan = true;

        return CheckoutMutationOutcome::success($currentSelections, sectionHtml($requiredSections));
    },
);

assertOrchestrator($handlerRan, 'valid mutation handler must run');
assertOrchestrator($result->status === CheckoutMutationExecutionStatus::Completed, 'valid mutation must complete');
assertOrchestrator($result->refreshResult?->success === true, 'valid mutation must return successful refresh result');
assertOrchestrator($store->loads === 1 && $store->saves === 1, 'successful mutation must load and persist server selections inside the lock');
assertOrchestrator(OrchestratorLockState::$stateReadsOutsideLock === 0, 'guard and fresh-state rebuild must occur inside mutex');

$paymentResult = $orchestrator->execute(
    $context,
    $request,
    CheckoutMutation::PaymentSelected,
    static function ($state, array $requiredSections, CheckoutServerSelections $currentSelections): CheckoutMutationOutcome {
        return CheckoutMutationOutcome::success(
            new CheckoutServerSelections('demo:payment-option-1', $currentSelections->approvedAgreementKeys),
            sectionHtml($requiredSections),
        );
    },
);
assertOrchestrator($paymentResult->refreshResult?->success === true, 'payment selection mutation must complete');
assertOrchestrator($store->selections->selectedPaymentOption === 'demo:payment-option-1', 'validated handler output must be persisted server-side');
$newVersion = $paymentResult->refreshResult?->stateVersion;
assertOrchestrator(is_string($newVersion) && $newVersion !== $initialVersion, 'persisted payment selection must change authoritative state version');

$staleHandlerRan = false;
$staleSavesBefore = $store->saves;
$staleResult = $orchestrator->execute(
    $context,
    $request,
    CheckoutMutation::CarrierSelected,
    static function () use (&$staleHandlerRan): CheckoutMutationOutcome {
        $staleHandlerRan = true;
        throw new RuntimeException('must not run');
    },
);
assertOrchestrator(!$staleHandlerRan, 'stale mutation handler must not run');
assertOrchestrator($staleResult->status === CheckoutMutationExecutionStatus::Rejected, 'stale mutation must be rejected');
assertOrchestrator($staleResult->blockReason === CheckoutMutationBlockReason::StaleState, 'stale rejection reason must be preserved');
assertOrchestrator($store->saves === $staleSavesBefore, 'stale mutation must not persist selections');

$lockCallsBeforeInvalidCsrf = $mutex->calls;
$invalidCsrf = $orchestrator->execute(
    $context,
    array_replace($request, ['token' => 'wrong']),
    CheckoutMutation::CarrierSelected,
    static fn (): CheckoutMutationOutcome => throw new RuntimeException('must not run'),
);
assertOrchestrator($invalidCsrf->blockReason === CheckoutMutationBlockReason::InvalidCsrf, 'invalid CSRF must be rejected in preflight');
assertOrchestrator($mutex->calls === $lockCallsBeforeInvalidCsrf, 'invalid CSRF must not consume cart mutex');

$mutex->busy = true;
$busy = $orchestrator->execute(
    $context,
    array_replace($request, ['stateVersion' => $newVersion]),
    CheckoutMutation::CarrierSelected,
    static fn (): CheckoutMutationOutcome => throw new RuntimeException('must not run'),
);
assertOrchestrator($busy->status === CheckoutMutationExecutionStatus::Busy, 'lock contention must return busy status');
$mutex->busy = false;

$failureSavesBefore = $store->saves;
$failure = $orchestrator->execute(
    $context,
    array_replace($request, ['stateVersion' => $newVersion]),
    CheckoutMutation::CarrierSelected,
    static fn ($state, array $requiredSections, CheckoutServerSelections $currentSelections): CheckoutMutationOutcome => CheckoutMutationOutcome::failure(
        $currentSelections,
        [new CheckoutError('carrier_unavailable', 'Carrier is no longer available.')],
    ),
);
assertOrchestrator($failure->refreshResult?->success === false, 'business validation failure must use refresh failure contract');
assertOrchestrator($failure->refreshResult?->errors[0]->code === 'carrier_unavailable', 'business error code must survive orchestration');
assertOrchestrator($store->saves === $failureSavesBefore, 'failed mutation must not overwrite server selections');

$missingSectionSavesBefore = $store->saves;
try {
    $orchestrator->execute(
        $context,
        array_replace($request, ['stateVersion' => $newVersion]),
        CheckoutMutation::CarrierSelected,
        static fn ($state, array $requiredSections, CheckoutServerSelections $currentSelections): CheckoutMutationOutcome => CheckoutMutationOutcome::success(
            $currentSelections,
            ['summary' => '<div>summary only</div>'],
        ),
    );
    assertOrchestrator(false, 'successful handler missing required sections must fail loudly');
} catch (LogicException $exception) {
    assertOrchestrator(str_contains($exception->getMessage(), 'delivery'), 'missing dependency error must identify section');
}
assertOrchestrator($store->saves === $missingSectionSavesBefore, 'incomplete successful output must fail before persistence');

fwrite(STDOUT, "Checkout mutation orchestrator smoke tests passed.\n");

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
        return [];
    }

    public function isVirtualCart(): bool
    {
        return false;
    }

    public function getOrderTotal(bool $withTaxes, int $type): float
    {
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

use Jzvikas\OnePageCheckout\Checkout\CheckoutStateVersioner;
use Jzvikas\OnePageCheckout\Checkout\PrestaShopCheckoutStateFactory;
use Jzvikas\OnePageCheckout\Checkout\StaleCheckoutStateGuard;
use Jzvikas\OnePageCheckout\Security\CheckoutCsrfTokenValidator;
use Jzvikas\OnePageCheckout\Security\CheckoutMutationBlockReason;
use Jzvikas\OnePageCheckout\Security\CheckoutMutationGuard;

function assertGuard(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$cart = new Cart();
$context = new Context($cart, new Customer(9));
$stateFactory = new PrestaShopCheckoutStateFactory();
$versioner = new CheckoutStateVersioner();
$guard = new CheckoutMutationGuard(
    new CheckoutCsrfTokenValidator(),
    $stateFactory,
    new StaleCheckoutStateGuard($versioner),
);
$stateVersion = $versioner->version($stateFactory->create($context));
$validRequest = [
    'token' => 'csrf-token',
    'cartId' => '42',
    'stateVersion' => $stateVersion,
];

$valid = $guard->evaluate($context, $validRequest);
assertGuard($valid->allowed && $valid->reason === null, 'valid mutation context must be allowed');
assertGuard($valid->currentState?->cartId === 42, 'allowed result must expose current server state');

$invalidCsrf = $guard->evaluate($context, array_replace($validRequest, ['token' => 'wrong']));
assertGuard(
    !$invalidCsrf->allowed && $invalidCsrf->reason === CheckoutMutationBlockReason::InvalidCsrf,
    'invalid CSRF must be blocked'
);

$crossCart = $guard->evaluate($context, array_replace($validRequest, ['cartId' => '43']));
assertGuard(
    !$crossCart->allowed && $crossCart->reason === CheckoutMutationBlockReason::CrossCart,
    'cross-cart request must be blocked'
);

$badCartBinding = $guard->evaluate($context, array_replace($validRequest, ['cartId' => '42x']));
assertGuard(
    !$badCartBinding->allowed && $badCartBinding->reason === CheckoutMutationBlockReason::InvalidCartBinding,
    'non-integer cart binding must be blocked'
);

$mismatchedCustomer = new Context($cart, new Customer(10));
$customerMismatch = $guard->evaluate($mismatchedCustomer, $validRequest);
assertGuard(
    !$customerMismatch->allowed && $customerMismatch->reason === CheckoutMutationBlockReason::CustomerMismatch,
    'cart/customer mismatch must be blocked'
);

$stale = $guard->evaluate($context, array_replace($validRequest, ['stateVersion' => 'v1:stale']));
assertGuard(
    !$stale->allowed && $stale->reason === CheckoutMutationBlockReason::StaleState,
    'stale state must be blocked'
);
assertGuard($stale->currentState?->cartId === 42, 'stale result must include fresh server state for recovery');

$missingCart = $guard->evaluate(new Context(null, new Customer(9)), $validRequest);
assertGuard(
    !$missingCart->allowed && $missingCart->reason === CheckoutMutationBlockReason::MissingCart,
    'missing context cart must be blocked'
);

fwrite(STDOUT, "Checkout mutation guard smoke tests passed.\n");

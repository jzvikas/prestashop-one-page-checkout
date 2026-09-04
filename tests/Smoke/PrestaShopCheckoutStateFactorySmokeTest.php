<?php

declare(strict_types=1);

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

    /** @var list<array{id_cart_rule:int}> */
    public array $rules = [
        ['id_cart_rule' => 8],
        ['id_cart_rule' => 4],
        ['id_cart_rule' => 8],
    ];

    /** @var array<string, float> */
    public array $totals = [
        '1:0' => 120.5,
        '0:0' => 100.0,
        '1:1' => 110.0,
        '1:2' => 5.5,
        '1:3' => 10.0,
        '1:4' => 0.0,
    ];

    public function getCartRules(): array
    {
        return $this->rules;
    }

    public function isVirtualCart(): bool
    {
        return false;
    }

    public function getOrderTotal(bool $withTaxes, int $type): float
    {
        return (float) $this->totals[(int) $withTaxes . ':' . $type];
    }
}

final class Context
{
    public function __construct(public ?Cart $cart) {}
}

require dirname(__DIR__) . '/bootstrap.php';

use Jzvikas\OnePageCheckout\Checkout\CheckoutServerSelections;
use Jzvikas\OnePageCheckout\Checkout\PrestaShopCheckoutStateFactory;

function assertSameFactoryValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(
            STDERR,
            sprintf(
                "FAIL: %s\nExpected: %s\nActual: %s\n",
                $message,
                var_export($expected, true),
                var_export($actual, true),
            )
        );
        exit(1);
    }
}

$cart = new Cart();
$factory = new PrestaShopCheckoutStateFactory();
$state = $factory->create(
    new Context($cart),
    new CheckoutServerSelections(' module-pay ', ['tos-b', 'tos-a', 'tos-a'])
);

assertSameFactoryValue(2, $state->shopId, 'shop id comes from server cart');
assertSameFactoryValue(42, $state->cartId, 'cart id comes from server cart');
assertSameFactoryValue(9, $state->customerId, 'customer id comes from server cart');
assertSameFactoryValue(7, $state->carrierId, 'carrier id comes from server cart');
assertSameFactoryValue('module-pay', $state->selectedPaymentOption, 'payment selection is normalized');
assertSameFactoryValue(['tos-a', 'tos-b'], $state->approvedAgreementKeys, 'agreement keys are normalized');

$firstCartFingerprint = $state->cartFingerprint;
$firstTotalsFingerprint = $state->totalsFingerprint;

$cart->rules = [['id_cart_rule' => 4], ['id_cart_rule' => 8]];
$stateSameRulesDifferentOrder = $factory->create(new Context($cart));
assertSameFactoryValue(
    $firstCartFingerprint,
    $stateSameRulesDifferentOrder->cartFingerprint,
    'cart-rule ordering must not change fingerprint'
);

$cart->id_carrier = 9;
$changedCarrier = $factory->create(new Context($cart));
if ($changedCarrier->cartFingerprint === $firstCartFingerprint) {
    fwrite(STDERR, "FAIL: carrier mutation must change cart fingerprint\n");
    exit(1);
}

$cart->id_carrier = 7;
$cart->totals['1:0'] = 121.0;
$changedTotal = $factory->create(new Context($cart));
if ($changedTotal->totalsFingerprint === $firstTotalsFingerprint) {
    fwrite(STDERR, "FAIL: Core total mutation must change totals fingerprint\n");
    exit(1);
}

$anonymous = new Cart();
$anonymous->id_customer = 0;
$anonymous->id_address_delivery = 0;
$anonymous->id_address_invoice = 0;
$anonymous->id_carrier = 0;
$anonymousState = $factory->create(new Context($anonymous));
assertSameFactoryValue(null, $anonymousState->customerId, 'zero customer id maps to null');
assertSameFactoryValue(null, $anonymousState->deliveryAddressId, 'zero delivery address maps to null');
assertSameFactoryValue(null, $anonymousState->carrierId, 'zero carrier maps to null');

fwrite(STDOUT, "PrestaShop checkout state factory smoke tests passed.\n");

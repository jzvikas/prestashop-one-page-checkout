<?php

declare(strict_types=1);

class Customer
{
    /** @var array<string,bool> */
    public static array $owned = [];

    public static function customerHasAddress(int $customerId, int $addressId): bool
    {
        return self::$owned[$customerId . ':' . $addressId] ?? false;
    }
}

class Cart
{
    public int $id = 42;
    public int $id_customer = 9;
    public int $id_address_delivery = 11;
    public string $delivery_option = '';

    public function __construct(private bool $virtual = false)
    {
    }

    public function isVirtualCart(): bool
    {
        return $this->virtual;
    }
}

class Context
{
    public Cart $cart;

    public function __construct(bool $virtual = false)
    {
        $this->cart = new Cart($virtual);
    }
}

require dirname(__DIR__) . '/bootstrap.php';

use Jzvikas\OnePageCheckout\Checkout\Carrier\CheckoutCarrierSelectionException;
use Jzvikas\OnePageCheckout\Checkout\Carrier\CheckoutCarrierSelectionParser;
use Jzvikas\OnePageCheckout\Checkout\Carrier\CheckoutCarrierSelectionService;
use Jzvikas\OnePageCheckout\Checkout\Rendering\CheckoutSessionProviderInterface;

final class CarrierSession
{
    public array $options = ['2,' => [], '4,7,' => []];
    public ?string $selectedFallback = '2,';
    public array $writes = [];

    public function __construct(private readonly Cart $cart)
    {
    }

    public function getDeliveryOptions(): array
    {
        return $this->options;
    }

    public function getSelectedDeliveryOption(): ?string
    {
        return $this->selectedFallback;
    }

    public function setDeliveryOption(array $option): bool
    {
        $this->writes[] = $option;
        $this->cart->delivery_option = (string) json_encode($option);

        return true;
    }
}

final class CarrierSessionProvider implements CheckoutSessionProviderInterface
{
    public ?CarrierSession $lastSession = null;

    public function get(\Context $context): object
    {
        $this->lastSession = new CarrierSession($context->cart);

        return $this->lastSession;
    }
}

function assertCarrierSelection(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$parser = new CheckoutCarrierSelectionParser();
assertCarrierSelection($parser->parse(['deliveryOption' => '4,7,'])->deliveryOption === '4,7,', 'valid Core delivery option key must parse');

foreach (['', '2<script>', ' 2,', '02,', '2', '2,,', '0,', str_repeat('1', 256)] as $invalid) {
    try {
        $parser->parse(['deliveryOption' => $invalid]);
        assertCarrierSelection(false, 'malformed delivery option must be rejected');
    } catch (CheckoutCarrierSelectionException) {
    }
}

Customer::$owned = ['9:11' => true];
$provider = new CarrierSessionProvider();
$service = new CheckoutCarrierSelectionService($provider);
$context = new Context();

// Core getSelectedDeliveryOption() may auto-select a fallback even when Cart::$delivery_option is
// still empty. The first explicit shopper selection must therefore still persist.
assertCarrierSelection(
    $service->apply($context, $parser->parse(['deliveryOption' => '2,'])) === true,
    'Core auto-selected fallback must not suppress the first explicit persisted selection',
);
assertCarrierSelection(
    $provider->lastSession?->writes === [[11 => '2,']],
    'carrier persistence must use Core address-keyed delivery_option payload shape',
);
assertCarrierSelection(
    json_decode($context->cart->delivery_option, true) === [11 => '2,'],
    'carrier selection must be retained on the cart delivery_option map',
);

assertCarrierSelection(
    $service->apply($context, $parser->parse(['deliveryOption' => '2,'])) === false,
    'reselecting the actually persisted option must be idempotent',
);
assertCarrierSelection(
    $provider->lastSession?->writes === [],
    'idempotent persisted selection must not rewrite Core cart state',
);

assertCarrierSelection(
    $service->apply($context, $parser->parse(['deliveryOption' => '4,7,'])) === true,
    'another fresh Core option must be persisted',
);
assertCarrierSelection(
    $provider->lastSession?->writes === [[11 => '4,7,']],
    'changed carrier selection must preserve the server-owned delivery address key',
);

try {
    $service->apply($context, $parser->parse(['deliveryOption' => '99,']));
    assertCarrierSelection(false, 'forged delivery option must be rejected');
} catch (CheckoutCarrierSelectionException) {
}

$foreignAddress = new Context();
$foreignAddress->cart->id_address_delivery = 99;
try {
    $service->apply($foreignAddress, $parser->parse(['deliveryOption' => '2,']));
    assertCarrierSelection(false, 'carrier mutation must reject a delivery address not owned by the cart customer');
} catch (CheckoutCarrierSelectionException) {
}

$anonymous = new Context();
$anonymous->cart->id_customer = 0;
try {
    $service->apply($anonymous, $parser->parse(['deliveryOption' => '2,']));
    assertCarrierSelection(false, 'carrier mutation must require a real cart-bound checkout customer');
} catch (CheckoutCarrierSelectionException) {
}

try {
    $service->apply(new Context(true), $parser->parse(['deliveryOption' => '2,']));
    assertCarrierSelection(false, 'virtual carts must reject carrier mutation');
} catch (CheckoutCarrierSelectionException) {
}

echo "CheckoutCarrierSelectionSmokeTest OK\n";

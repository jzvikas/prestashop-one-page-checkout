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
    public int $id_address_invoice = 12;
    public int $saveCalls = 0;
    public int $updateAddressIdCalls = 0;

    public function updateAddressId(int $oldAddressId, int $newAddressId): void
    {
        ++$this->updateAddressIdCalls;
        if ($this->id_address_invoice === $oldAddressId) {
            $this->id_address_invoice = $newAddressId;
        }
    }

    public function save(): bool
    {
        ++$this->saveCalls;

        return true;
    }
}

final class Context
{
    public function __construct(public ?Cart $cart) {}
}

require dirname(__DIR__) . '/bootstrap.php';

use Jzvikas\OnePageCheckout\Checkout\Address\CheckoutAddressSelectionException;
use Jzvikas\OnePageCheckout\Checkout\Address\CheckoutAddressSelectionParser;
use Jzvikas\OnePageCheckout\Checkout\Address\CheckoutAddressSelectionService;
use Jzvikas\OnePageCheckout\Checkout\Rendering\CheckoutSessionProviderInterface;

final class FakeCheckoutSession
{
    public int $deliveryCalls = 0;
    public int $invoiceCalls = 0;

    public function __construct(private readonly Cart $cart) {}

    public function setIdAddressDelivery(int $addressId): self
    {
        ++$this->deliveryCalls;
        $this->cart->updateAddressId($this->cart->id_address_delivery, $addressId);
        $this->cart->id_address_delivery = $addressId;
        $this->cart->save();

        return $this;
    }

    public function setIdAddressInvoice(int $addressId): self
    {
        ++$this->invoiceCalls;
        $this->cart->id_address_invoice = $addressId;
        $this->cart->save();

        return $this;
    }
}

final class FakeCheckoutSessionProvider implements CheckoutSessionProviderInterface
{
    public int $getCalls = 0;
    public ?FakeCheckoutSession $lastSession = null;

    public function get(\Context $context): object
    {
        ++$this->getCalls;
        $this->lastSession = new FakeCheckoutSession($context->cart);

        return $this->lastSession;
    }
}

function assertAddressSelection(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$parser = new CheckoutAddressSelectionParser();
$provider = new FakeCheckoutSessionProvider();
$service = new CheckoutAddressSelectionService($provider);
Customer::$owned = [
    '9:11' => true,
    '9:12' => true,
    '9:20' => true,
    '9:30' => true,
];

$same = $parser->parse([
    'deliveryAddressId' => '20',
    'useSameAddress' => '1',
]);
$cart = new Cart();
$changed = $service->apply(new Context($cart), $same);
assertAddressSelection($changed, 'new delivery selection must change cart');
assertAddressSelection($cart->id_address_delivery === 20, 'delivery address must be persisted on cart object');
assertAddressSelection($cart->id_address_invoice === 20, 'same-address mode must mirror invoice to delivery');
assertAddressSelection($cart->updateAddressIdCalls === 1, 'delivery changes must use Core updateAddressId semantics');
assertAddressSelection($provider->lastSession?->deliveryCalls === 1, 'delivery must be changed through CheckoutSession');
assertAddressSelection($provider->lastSession?->invoiceCalls === 1, 'unlinked invoice must be explicitly synchronized');

$separate = $parser->parse([
    'deliveryAddressId' => 20,
    'invoiceAddressId' => '30',
    'useSameAddress' => false,
]);
$cart = new Cart();
$service->apply(new Context($cart), $separate);
assertAddressSelection($cart->id_address_delivery === 20, 'separate mode may update delivery');
assertAddressSelection($cart->id_address_invoice === 30, 'separate mode must persist explicit invoice address');

$linkedCart = new Cart();
$linkedCart->id_address_invoice = $linkedCart->id_address_delivery;
$service->apply(new Context($linkedCart), $same);
assertAddressSelection($linkedCart->id_address_delivery === 20, 'linked delivery must change');
assertAddressSelection($linkedCart->id_address_invoice === 20, 'Core delivery update must keep linked invoice synchronized');
assertAddressSelection($provider->lastSession?->invoiceCalls === 0, 'service must re-read Core side effects before issuing invoice write');

$noChangeProviderCalls = $provider->getCalls;
$noChangeCart = new Cart();
$noChange = $parser->parse([
    'invoiceAddressId' => '12',
    'useSameAddress' => '0',
]);
assertAddressSelection(!$service->apply(new Context($noChangeCart), $noChange), 'identical address selection must be idempotent');
assertAddressSelection($provider->getCalls === $noChangeProviderCalls, 'idempotent selection must not resolve/write CheckoutSession');

try {
    $foreign = $parser->parse([
        'deliveryAddressId' => 99,
        'useSameAddress' => true,
    ]);
    $service->apply(new Context(new Cart()), $foreign);
    assertAddressSelection(false, 'foreign delivery address must be rejected');
} catch (CheckoutAddressSelectionException $exception) {
    assertAddressSelection($exception->errorCode === 'delivery_address_not_owned', 'foreign delivery rejection must use stable IDOR error code');
    assertAddressSelection($exception->field === 'deliveryAddressId', 'foreign delivery error must identify field');
}

try {
    $parser->parse(['useSameAddress' => false]);
    assertAddressSelection(false, 'separate invoice mode without invoice id must be rejected');
} catch (CheckoutAddressSelectionException $exception) {
    assertAddressSelection($exception->errorCode === 'invoice_address_required', 'missing invoice must have stable code');
}

try {
    $parser->parse(['deliveryAddressId' => '20x', 'useSameAddress' => true]);
    assertAddressSelection(false, 'malformed address id must be rejected');
} catch (CheckoutAddressSelectionException $exception) {
    assertAddressSelection($exception->errorCode === 'invalid_address_id', 'malformed id must use stable input error code');
}

try {
    $parser->parse(['invoiceAddressId' => 12, 'useSameAddress' => true]);
    assertAddressSelection(false, 'client invoice id must be rejected in same-address mode');
} catch (CheckoutAddressSelectionException $exception) {
    assertAddressSelection($exception->errorCode === 'invoice_address_must_be_omitted', 'ambiguous same-address payload must have stable code');
}

$anonymousCart = new Cart();
$anonymousCart->id_customer = 0;
try {
    $service->apply(new Context($anonymousCart), $same);
    assertAddressSelection(false, 'address selection before checkout customer exists must be rejected');
} catch (CheckoutAddressSelectionException $exception) {
    assertAddressSelection($exception->errorCode === 'checkout_customer_required', 'missing checkout customer must use stable code');
}

fwrite(STDOUT, "Checkout address selection smoke tests passed.\n");

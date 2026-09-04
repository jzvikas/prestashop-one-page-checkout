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
    public bool $saveResult = true;

    public function save(): bool
    {
        ++$this->saveCalls;

        return $this->saveResult;
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

function assertAddressSelection(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$parser = new CheckoutAddressSelectionParser();
$service = new CheckoutAddressSelectionService();
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
assertAddressSelection($cart->saveCalls === 1, 'changed address context must save exactly once');

$separate = $parser->parse([
    'deliveryAddressId' => 20,
    'invoiceAddressId' => '30',
    'useSameAddress' => false,
]);
$cart = new Cart();
$service->apply(new Context($cart), $separate);
assertAddressSelection($cart->id_address_delivery === 20, 'separate mode may update delivery');
assertAddressSelection($cart->id_address_invoice === 30, 'separate mode must persist explicit invoice address');

$noChangeCart = new Cart();
$noChange = $parser->parse([
    'invoiceAddressId' => '12',
    'useSameAddress' => '0',
]);
assertAddressSelection(!$service->apply(new Context($noChangeCart), $noChange), 'identical address selection must be idempotent');
assertAddressSelection($noChangeCart->saveCalls === 0, 'idempotent selection must not write cart');

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

$saveFailureCart = new Cart();
$saveFailureCart->saveResult = false;
try {
    $service->apply(
        new Context($saveFailureCart),
        $parser->parse(['deliveryAddressId' => 20, 'useSameAddress' => true]),
    );
    assertAddressSelection(false, 'cart save failure must be surfaced');
} catch (CheckoutAddressSelectionException $exception) {
    assertAddressSelection($exception->errorCode === 'address_context_save_failed', 'save failure must use stable system code');
    assertAddressSelection($saveFailureCart->id_address_delivery === 11, 'failed save must restore delivery id in memory');
    assertAddressSelection($saveFailureCart->id_address_invoice === 12, 'failed save must restore invoice id in memory');
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

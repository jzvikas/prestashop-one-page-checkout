<?php

declare(strict_types=1);

class Context
{
    public object $cart;
    public Customer $customer;
    public object $language;
}

class Customer
{
    public int $id = 42;

    public function getAddresses(int $languageId): array
    {
        assert($languageId === 1);

        return [
            ['id_address' => 12],
            ['id_address' => 13],
        ];
    }

    public static function customerHasAddress(int $customerId, int $addressId): bool
    {
        return $customerId === 42 && $addressId === 12;
    }
}

class Address
{
    public int $id;
    public string $alias;

    public function __construct(int $id, int $languageId)
    {
        assert($languageId === 1);
        $this->id = $id;
        $this->alias = $id === 12 ? 'Home <safe>' : 'Other';
    }
}

class Validate
{
    public static function isLoadedObject(Address $address): bool
    {
        return $address->id > 0;
    }
}

class AddressFormat
{
    public static function generateAddress(Address $address): string
    {
        return $address->id === 12 ? "Ada Lovelace\n1 Main Street\nVilnius" : '';
    }
}

require_once dirname(__DIR__) . '/bootstrap.php';

use Jzvikas\OnePageCheckout\Checkout\Rendering\PrestaShopCheckoutAddressBookPresenter;

$context = new Context();
$context->cart = (object) [
    'id_customer' => 42,
    'id_address_delivery' => 12,
    'id_address_invoice' => 12,
];
$context->customer = new Customer();
$context->language = (object) ['id' => 1];

$presented = (new PrestaShopCheckoutAddressBookPresenter())->present($context);
assert($presented['deliveryAddressId'] === 12);
assert($presented['invoiceAddressId'] === 12);
assert($presented['useSameAddress'] === true);
assert(count($presented['addresses']) === 1);
assert($presented['addresses'][0] === [
    'id' => 12,
    'alias' => 'Home <safe>',
    'lines' => ['Ada Lovelace', '1 Main Street', 'Vilnius'],
]);

$context->customer->id = 99;
try {
    (new PrestaShopCheckoutAddressBookPresenter())->present($context);
    assert(false, 'Cart/customer mismatch must fail closed.');
} catch (LogicException) {
}

echo "PrestaShopCheckoutAddressBookPresenterSmokeTest OK\n";

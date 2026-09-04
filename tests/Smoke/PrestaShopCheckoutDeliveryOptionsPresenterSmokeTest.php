<?php

declare(strict_types=1);

class Cart
{
    public int $id = 42;

    public function __construct(private readonly bool $virtual = false)
    {
    }

    public function isVirtualCart(): bool
    {
        return $this->virtual;
    }
}

class Hook
{
    /** @var list<string> */
    public static array $calls = [];

    public static function exec(string $name, array $params = []): string
    {
        self::$calls[] = $name;

        return match ($name) {
            'displayBeforeCarrier' => '<before-carrier>',
            'displayAfterCarrier' => '<after-carrier>',
            default => '',
        };
    }
}

class DeliveryCheckoutSessionFake
{
    public function getDeliveryOptions(): array
    {
        return [
            '7,' => [
                'name' => 'Fast carrier',
                'delay' => 'Tomorrow',
                'price' => '5.00 EUR',
                'extraContent' => '<carrier-extra>',
            ],
        ];
    }

    public function getSelectedDeliveryOption(): string
    {
        return '7,';
    }
}

class DeliveryControllerFake
{
    public function getCheckoutSession(): object
    {
        return new DeliveryCheckoutSessionFake();
    }
}

class Context
{
    public Cart $cart;
    public object $controller;

    public function __construct(bool $virtual = false)
    {
        $this->cart = new Cart($virtual);
        $this->controller = new DeliveryControllerFake();
    }
}

require_once dirname(__DIR__) . '/bootstrap.php';

use Jzvikas\OnePageCheckout\Checkout\Rendering\PrestaShopCheckoutDeliveryOptionsPresenter;

$presenter = new PrestaShopCheckoutDeliveryOptionsPresenter();
$presented = $presenter->present(new Context());

assert($presented['isVirtual'] === false);
assert(array_keys($presented['deliveryOptions']) === ['7,']);
assert($presented['deliveryOptions']['7,']['name'] === 'Fast carrier');
assert($presented['selectedDeliveryOption'] === '7,');
assert($presented['hookDisplayBeforeCarrier'] === '<before-carrier>');
assert($presented['hookDisplayAfterCarrier'] === '<after-carrier>');
assert(Hook::$calls === ['actionCarrierProcess', 'displayBeforeCarrier', 'displayAfterCarrier']);

Hook::$calls = [];
$virtual = $presenter->present(new Context(true));
assert($virtual['isVirtual'] === true);
assert($virtual['deliveryOptions'] === []);
assert($virtual['selectedDeliveryOption'] === null);
assert(Hook::$calls === []);

echo "PrestaShopCheckoutDeliveryOptionsPresenterSmokeTest OK\n";

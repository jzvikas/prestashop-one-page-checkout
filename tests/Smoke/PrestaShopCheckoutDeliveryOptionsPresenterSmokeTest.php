<?php

declare(strict_types=1);

class Cart
{
    public int $id = 42;

    /** @var list<array{country: mixed, flush: bool}> */
    public array $deliveryOptionListCalls = [];

    public function __construct(private readonly bool $virtual = false)
    {
    }

    public function isVirtualCart(): bool
    {
        return $this->virtual;
    }

    public function getDeliveryOptionList(mixed $country = null, bool $flush = false): array
    {
        $this->deliveryOptionListCalls[] = [
            'country' => $country,
            'flush' => $flush,
        ];

        return ['7,' => [['id_carrier' => 7]]];
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

use Jzvikas\OnePageCheckout\Checkout\Rendering\CheckoutSessionProviderInterface;
use Jzvikas\OnePageCheckout\Checkout\Rendering\PrestaShopCheckoutDeliveryOptionsPresenter;

$sessionProvider = new class implements CheckoutSessionProviderInterface {
    public function get(Context $context): object
    {
        return $context->controller->getCheckoutSession();
    }
};
$presenter = new PrestaShopCheckoutDeliveryOptionsPresenter($sessionProvider);
$context = new Context();
$presented = $presenter->present($context);

assert($presented['isVirtual'] === false);
assert(array_keys($presented['deliveryOptions']) === ['7,']);
assert($presented['deliveryOptions']['7,']['name'] === 'Fast carrier');
assert($presented['selectedDeliveryOption'] === '7,');
assert($presented['hookDisplayBeforeCarrier'] === '<before-carrier>');
assert($presented['hookDisplayAfterCarrier'] === '<after-carrier>');
assert(Hook::$calls === ['actionCarrierProcess', 'displayBeforeCarrier', 'displayAfterCarrier']);
assert($context->cart->deliveryOptionListCalls === [['country' => null, 'flush' => true]]);

$source = file_get_contents(dirname(__DIR__, 2) . '/src/Checkout/Rendering/PrestaShopCheckoutDeliveryOptionsPresenter.php');
assert(is_string($source));
$carrierHookPosition = strpos($source, "\\Hook::exec('actionCarrierProcess'");
$cacheRefreshPosition = strpos($source, 'getDeliveryOptionList(null, true)');
$sessionPresentationPosition = strpos($source, '$checkoutSession->getDeliveryOptions()');
assert(is_int($carrierHookPosition));
assert(is_int($cacheRefreshPosition));
assert(is_int($sessionPresentationPosition));
assert($carrierHookPosition < $cacheRefreshPosition);
assert($cacheRefreshPosition < $sessionPresentationPosition);
assert(!str_contains($source, 'setDeliveryOption('));

Hook::$calls = [];
$virtualContext = new Context(true);
$virtual = $presenter->present($virtualContext);
assert($virtual['isVirtual'] === true);
assert($virtual['deliveryOptions'] === []);
assert($virtual['selectedDeliveryOption'] === null);
assert(Hook::$calls === []);
assert($virtualContext->cart->deliveryOptionListCalls === []);

echo "PrestaShopCheckoutDeliveryOptionsPresenterSmokeTest OK\n";
